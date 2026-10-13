<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Pest\Browser\Api\AwaitableWebpage;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Relaticle\Chat\Support\TurnPresence;
use Tests\Helpers\ChatBrowser;
use Tests\Helpers\ChatDocument;

mutates(ChatInterface::class);

function sendWithoutWebsocket(AwaitableWebpage $page, string $text): void
{
    $resolveInterface = ChatBrowser::resolveInterface();

    $page->script(<<<JS
        (() => {
            {$resolveInterface}

            window.Echo = null;
            data.turnPollMs = 200;
            data.localEditor().setText('{$text}');
            data.sendMessage();

            return true;
        })();
    JS);

    $page->assertScript(<<<JS
        (() => {
            {$resolveInterface}
            return data.messages.find((m) => m.role === 'user')?.sendState ?? null;
        })()
    JS, 'sent');
}

function persistReply(User $user, string $conversationId, string $content): void
{
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::ulid(),
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
        'role' => 'assistant',
        'content' => $content,
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'steps' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function endTurn(string $conversationId): void
{
    TurnPresence::clear($conversationId, TurnPresence::current($conversationId)['turn_id']);
}

function lastAssistantBubble(AwaitableWebpage $page): string
{
    $resolveInterface = ChatBrowser::resolveInterface();

    return <<<JS
        (() => {
            {$resolveInterface}
            const bubble = data.messages.findLast((m) => m.role === 'assistant');
            return JSON.stringify({
                content: bubble?.content ?? null,
                error: bubble?.streamError ?? null,
                streaming: data.isStreaming,
            });
        })()
    JS;
}

it('shows the saved reply when no websocket ever delivers the stream', function (): void {
    Queue::fake();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = ChatBrowser::seedConversation($user, $workspace->getKey(), 'No websocket');

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('placeholder="Ask anything..."');

    sendWithoutWebsocket($page, 'hello without a socket');

    persistReply($user, $conversationId, 'Saved while the socket was silent.');
    endTurn($conversationId);

    $page->assertScript(lastAssistantBubble($page), json_encode([
        'content' => 'Saved while the socket was silent.',
        'error' => null,
        'streaming' => false,
    ]));
});

it('keeps waiting while the turn is still running', function (): void {
    Queue::fake();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = ChatBrowser::seedConversation($user, $workspace->getKey(), 'Still running');
    persistReply($user, $conversationId, 'The reply to an earlier turn.');

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('placeholder="Ask anything..."');

    sendWithoutWebsocket($page, 'a slow question');

    $page->wait(1)->assertScript(lastAssistantBubble($page), json_encode([
        'content' => '',
        'error' => null,
        'streaming' => true,
    ]));
});

it('reports a turn that ended without a reply and never repeats the previous one', function (): void {
    Queue::fake();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = ChatBrowser::seedConversation($user, $workspace->getKey(), 'Failed turn');
    persistReply($user, $conversationId, 'The reply to an earlier turn.');

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('placeholder="Ask anything..."');

    sendWithoutWebsocket($page, 'a question that fails');

    endTurn($conversationId);

    $page->assertScript(lastAssistantBubble($page), json_encode([
        'content' => '',
        'error' => 'The assistant encountered an error. Please try again.',
        'streaming' => false,
    ]));
});

it('shows the saved reply when the inactivity watchdog runs out', function (): void {
    Queue::fake();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = ChatBrowser::seedConversation($user, $workspace->getKey(), 'Watchdog');

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('placeholder="Ask anything..."');

    sendWithoutWebsocket($page, 'hello watchdog');

    $resolveInterface = ChatBrowser::resolveInterface();
    $page->script(<<<JS
        (() => {
            {$resolveInterface}
            data.stopWatchingTurn();
            return true;
        })();
    JS);

    persistReply($user, $conversationId, 'Saved before the watchdog fired.');
    endTurn($conversationId);

    $page->script(<<<JS
        (() => {
            {$resolveInterface}
            data.startStreamTimeout(50);
            return true;
        })();
    JS);

    $page->assertScript(lastAssistantBubble($page), json_encode([
        'content' => 'Saved before the watchdog fired.',
        'error' => null,
        'streaming' => false,
    ]));
});
