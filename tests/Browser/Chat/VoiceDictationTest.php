<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Relaticle\Chat\Http\Controllers\TranscribeController;
use Tests\Helpers\ChatBrowser;

mutates(TranscribeController::class);

beforeEach(function (): void {
    config([
        'chat.voice_enabled' => true,
        'ai.default_for_transcription' => 'openai',
        'ai.providers.openai.key' => 'test-key',
    ]);
});

it('tells the user when the provider cannot read a recording', function (): void {
    Http::fake(['api.openai.com/*' => Http::response([
        'error' => ['message' => 'Audio file might be corrupted or unsupported', 'type' => 'invalid_request_error'],
    ], 400)]);

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $workspace->getKey(), 'voice test', $conversationId);

    $recording = base64_encode((string) file_get_contents(base_path('tests/fixtures/audio/recording.m4a')));

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('placeholder="Ask anything..."');

    $page->script(<<<JS
        (() => {
            const host = document.querySelector('[x-data^="voiceRecorder"]');
            const bytes = Uint8Array.from(atob('{$recording}'), (c) => c.charCodeAt(0));
            Alpine.\$data(host).transcribeBlob(new Blob([bytes], { type: 'audio/mp4' }));
        })()
        JS);

    $page->waitForText("That recording couldn't be read. Try again.")
        ->assertDontSee('Could not transcribe that recording. Try again.')
        ->assertNoJavaScriptErrors();

    $page->screenshot(filename: 'voice-dictation-unreadable');
});
