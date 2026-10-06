<?php

declare(strict_types=1);

use App\Enums\CustomFields\PeopleField;
use App\Features\EmailIntegration;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\Email\ListEmailsTool;
use App\Models\CustomField;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Models\WorkspaceEmailBlocklist;
use Relaticle\EmailIntegration\Policies\EmailPolicy;
use Relaticle\EmailIntegration\Queries\VisibleEmailsQuery;
use Relaticle\EmailIntegration\Support\EmailForAgent;

mutates(ListEmailsTool::class, VisibleEmailsQuery::class, EmailForAgent::class, EmailPolicy::class);

beforeEach(function (): void {
    $this->viewer = User::factory()->withWorkspace()->create();
    $this->workspace = $this->viewer->currentWorkspace;

    $this->coworker = User::factory()->create();
    $this->coworker->workspaces()->attach($this->workspace);
    $this->coworker->forceFill(['current_workspace_id' => $this->workspace->id])->save();

    $this->viewerAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->viewer->id,
    ]));

    $this->coworkerAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->coworker->id,
    ]));

    $this->emailFrom = function (User $owner, array $attributes = [], string $sender = 'client@acme.test'): Email {
        $email = Email::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $owner->id,
            'connected_account_id' => $owner->is($this->viewer) ? $this->viewerAccount->getKey() : $this->coworkerAccount->getKey(),
            'is_internal' => false,
            ...$attributes,
        ]);

        EmailParticipant::query()->create([
            'email_id' => $email->id,
            'email_address' => $sender,
            'name' => 'Acme Client',
            'role' => EmailParticipantRole::FROM,
        ]);

        return $email;
    };
});

function listedEmails(User $user, array $arguments = []): array
{
    $items = [];

    RelaticleServer::actingAs($user)
        ->tool(ListEmailsTool::class, $arguments)
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use (&$items): AssertableJson {
            $items = $json->toArray()['items'];

            return $json->etc();
        });

    return $items;
}

function listedToolNames(User $user, array $abilities): array
{
    auth()->forgetGuards();

    return test()
        ->withToken($user->createToken('test-'.Str::random(6), $abilities)->plainTextToken)
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertOk()
        ->json('result.tools.*.name');
}

it('shows the caller their own email in full', function (): void {
    $email = ($this->emailFrom)($this->viewer, ['subject' => 'Renewal terms', 'snippet' => 'Here is the draft contract']);

    $items = listedEmails($this->viewer);

    expect($items)->toHaveCount(1)
        ->and($items[0]['id'])->toBe($email->id)
        ->and($items[0]['access'])->toBe('full')
        ->and($items[0]['subject'])->toBe('Renewal terms')
        ->and($items[0]['snippet'])->toBe('Here is the draft contract')
        ->and($items[0]['participants'][0])->toBe(['role' => 'from', 'name' => 'Acme Client', 'email' => 'client@acme.test']);
});

it('hides subject and snippet of a teammate email shared as metadata only', function (): void {
    ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::METADATA_ONLY]);

    $items = listedEmails($this->viewer);

    expect($items)->toHaveCount(1)
        ->and($items[0]['access'])->toBe('metadata_only')
        ->and($items[0]['subject'])->toBeNull()
        ->and($items[0]['snippet'])->toBeNull()
        ->and($items[0]['participants'])->not->toBeEmpty();
});

it('shows the subject but not the snippet of a teammate email shared at subject level', function (): void {
    ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::SUBJECT, 'subject' => 'Pricing call']);

    $items = listedEmails($this->viewer);

    expect($items[0]['access'])->toBe('subject')
        ->and($items[0]['subject'])->toBe('Pricing call')
        ->and($items[0]['snippet'])->toBeNull();
});

it('shows subject and snippet of a teammate email shared in full', function (): void {
    ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::FULL, 'subject' => 'Kickoff', 'snippet' => 'See you Monday']);

    $items = listedEmails($this->viewer);

    expect($items[0]['access'])->toBe('full')
        ->and($items[0]['subject'])->toBe('Kickoff')
        ->and($items[0]['snippet'])->toBe('See you Monday');
});

it('leaves a private teammate email out of the list', function (): void {
    ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::PRIVATE]);

    expect(listedEmails($this->viewer))->toBe([]);
});

it('lets a per-viewer share lower access below the email default', function (): void {
    $email = ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::FULL, 'subject' => 'Board notes', 'snippet' => 'Confidential']);

    EmailShare::factory()->tier(EmailPrivacyTier::METADATA_ONLY)->create([
        'workspace_id' => $this->workspace->id,
        'email_id' => $email->id,
        'shared_with' => $this->viewer->id,
        'shared_by' => $this->coworker->id,
    ]);

    $items = listedEmails($this->viewer);

    expect($items[0]['access'])->toBe('metadata_only')
        ->and($items[0]['subject'])->toBeNull()
        ->and($items[0]['snippet'])->toBeNull()
        ->and(listedEmails($this->viewer, ['search' => 'Board notes']))->toBe([]);
});

it('leaves another workspace email out of the list', function (): void {
    $otherWorkspace = Workspace::factory()->create();

    Email::factory()->full()->create(['workspace_id' => $otherWorkspace->id]);

    expect(listedEmails($this->viewer))->toBe([]);
});

it('leaves unsent mail out of the list', function (EmailStatus $status): void {
    ($this->emailFrom)($this->viewer, ['status' => $status]);
    ($this->emailFrom)($this->coworker, ['status' => $status, 'privacy_tier' => EmailPrivacyTier::FULL]);

    expect(listedEmails($this->viewer))->toBe([]);
})->with([
    'draft' => EmailStatus::DRAFT,
    'queued' => EmailStatus::QUEUED,
    'sending' => EmailStatus::SENDING,
    'failed' => EmailStatus::FAILED,
    'cancelled' => EmailStatus::CANCELLED,
]);

it('shows bcc recipients to the mailbox owner only', function (): void {
    $own = ($this->emailFrom)($this->viewer);
    $teammates = ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::FULL]);

    foreach ([$own, $teammates] as $email) {
        EmailParticipant::query()->create([
            'email_id' => $email->id,
            'email_address' => 'hidden@acme.test',
            'name' => null,
            'role' => EmailParticipantRole::BCC,
        ]);
    }

    $items = collect(listedEmails($this->viewer))->keyBy('id');

    expect(collect($items[$own->id]['participants'])->pluck('role'))->toContain('bcc')
        ->and(collect($items[$teammates->id]['participants'])->pluck('role'))->not->toContain('bcc');
});

it('lists one row for a message held in two mailboxes', function (): void {
    ($this->emailFrom)($this->viewer, ['rfc_message_id' => '<same@acme.test>']);
    ($this->emailFrom)($this->coworker, ['rfc_message_id' => '<same@acme.test>', 'privacy_tier' => EmailPrivacyTier::FULL]);

    $items = listedEmails($this->viewer);

    expect($items)->toHaveCount(1)
        ->and($items[0]['access'])->toBe('full');
});

it('filters by the linked record', function (): void {
    $person = People::factory()->recycle([$this->viewer, $this->workspace])->create();
    $linked = ($this->emailFrom)($this->viewer);
    ($this->emailFrom)($this->viewer);

    $person->emails()->attach($linked->id, ['link_source' => 'manual']);

    $items = listedEmails($this->viewer, ['record_type' => 'people', 'record_id' => $person->id]);

    expect(array_column($items, 'id'))->toBe([$linked->id]);
});

it('lists nothing for a record whose mailbox the workspace protects', function (): void {
    $person = People::factory()->recycle([$this->viewer, $this->workspace])->create();

    $emailsField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->id)
        ->where('entity_type', 'people')
        ->where('code', PeopleField::EMAILS->value)
        ->firstOrFail();

    $person->saveCustomFieldValue($emailsField, ['vip@acme.test'], $this->workspace);

    $email = ($this->emailFrom)($this->viewer, [], 'vip@acme.test');
    $person->emails()->attach($email->id, ['link_source' => 'manual']);

    $arguments = ['record_type' => 'people', 'record_id' => $person->id];

    expect(array_column(listedEmails($this->viewer, $arguments), 'id'))->toBe([$email->id]);

    WorkspaceEmailBlocklist::factory()->protected()->email('vip@acme.test')->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->viewer->id,
    ]);

    app()->forgetScopedInstances();

    expect(listedEmails($this->viewer, $arguments))->toBe([]);
});

it('lists nothing for a record in another workspace', function (): void {
    $foreign = People::factory()->create();

    expect(listedEmails($this->viewer, ['record_type' => 'people', 'record_id' => $foreign->id]))->toBe([]);
});

it('filters by search, direction and sent date', function (): void {
    $old = ($this->emailFrom)($this->viewer, ['subject' => 'Invoice 14', 'sent_at' => now()->subDays(10)]);
    $recent = ($this->emailFrom)($this->viewer, ['subject' => 'Invoice 15', 'sent_at' => now()->subDay()]);
    $sent = Email::factory()->outbound()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->viewer->id,
        'connected_account_id' => $this->viewerAccount->getKey(),
        'subject' => 'Follow up',
    ]);

    expect(array_column(listedEmails($this->viewer, ['search' => 'Invoice']), 'id'))->toEqualCanonicalizing([$old->id, $recent->id])
        ->and(array_column(listedEmails($this->viewer, ['direction' => 'outbound']), 'id'))->toBe([$sent->id])
        ->and(array_column(listedEmails($this->viewer, ['search' => 'Invoice', 'sent_after' => now()->subDays(3)->toIso8601String()]), 'id'))->toBe([$recent->id])
        ->and(array_column(listedEmails($this->viewer, ['search' => 'Invoice', 'sent_before' => now()->subDays(3)->toIso8601String()]), 'id'))->toBe([$old->id])
        ->and(array_column(listedEmails($this->viewer, ['search' => 'acme.test']), 'id'))->toEqualCanonicalizing([$old->id, $recent->id]);
});

it('lists newest first and pages', function (): void {
    $older = ($this->emailFrom)($this->viewer, ['sent_at' => now()->subHours(2)]);
    $newer = ($this->emailFrom)($this->viewer, ['sent_at' => now()->subHour()]);

    RelaticleServer::actingAs($this->viewer)
        ->tool(ListEmailsTool::class, ['per_page' => 1])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('items.0.id', $newer->id)
            ->where('total', 2)
            ->where('has_more', true)
            ->where('next_page', 2)
            ->etc());

    expect(array_column(listedEmails($this->viewer, ['per_page' => 1, 'page' => 2]), 'id'))->toBe([$older->id]);
});

it('rejects a record id without its type', function (): void {
    RelaticleServer::actingAs($this->viewer)
        ->tool(ListEmailsTool::class, ['record_id' => 'abc'])
        ->assertHasErrors();
});

it('keeps a full page of teammate email under a fixed query budget', function (): void {
    foreach (range(1, 25) as $i) {
        ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::SUBJECT]);
    }

    DB::enableQueryLog();
    $items = listedEmails($this->viewer, ['per_page' => 25]);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($items)->toHaveCount(25)
        ->and($queries)->toBeLessThan(77);
});

it('lists the email tool only for a token that holds the grant', function (): void {
    expect(listedToolNames($this->viewer, ['read']))->not->toContain('list-emails-tool')
        ->and(listedToolNames($this->viewer, ['read', 'email:read']))->toContain('list-emails-tool');
});

it('does not list the email tool while the email feature is off', function (): void {
    Feature::define(EmailIntegration::class, false);

    expect(listedToolNames($this->viewer, ['read', 'email:read']))->not->toContain('list-emails-tool');
});
