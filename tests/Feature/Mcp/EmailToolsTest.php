<?php

declare(strict_types=1);

use App\Enums\CustomFields\PeopleField;
use App\Features\EmailIntegration;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\Email\CreateEmailDraftTool;
use App\Mcp\Tools\Email\GetEmailTool;
use App\Mcp\Tools\Email\ListEmailAccountsTool;
use App\Mcp\Tools\Email\ListEmailsTool;
use App\Models\CustomField;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Filament\RichContent\SignatureBlock;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Models\EmailBlocklist;
use Relaticle\EmailIntegration\Models\EmailBody;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Models\EmailSignature;
use Relaticle\EmailIntegration\Models\WorkspaceEmailBlocklist;
use Relaticle\EmailIntegration\Policies\EmailPolicy;
use Relaticle\EmailIntegration\Queries\VisibleEmailsQuery;
use Relaticle\EmailIntegration\Support\AgentEmailBody;
use Relaticle\EmailIntegration\Support\EmailForAgent;

mutates(ListEmailsTool::class, GetEmailTool::class, ListEmailAccountsTool::class, CreateEmailDraftTool::class, AgentEmailBody::class, SignatureBlock::class, VisibleEmailsQuery::class, EmailForAgent::class, EmailPolicy::class);

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
    $own = ($this->emailFrom)($this->viewer, ['rfc_message_id' => '<same@acme.test>']);
    ($this->emailFrom)($this->coworker, ['rfc_message_id' => '<same@acme.test>', 'privacy_tier' => EmailPrivacyTier::METADATA_ONLY]);

    $items = listedEmails($this->viewer);

    expect($items)->toHaveCount(1)
        ->and($items[0]['id'])->toBe($own->id)
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
    $local = People::factory()->recycle([$this->viewer, $this->workspace])->create();
    $linked = ($this->emailFrom)($this->viewer);
    ($this->emailFrom)($this->viewer);

    $local->emails()->attach($linked->id, ['link_source' => 'manual']);

    expect(listedEmails($this->viewer, ['record_type' => 'people', 'record_id' => $foreign->id]))->toBe([])
        ->and(array_column(listedEmails($this->viewer, ['record_type' => 'people', 'record_id' => $local->id]), 'id'))->toBe([$linked->id]);
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
            ->where('has_more', true)
            ->where('next_page', 2)
            ->missing('total')
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
        ->and($queries)->toBeLessThan(76);
});

it('lists the email tool only for a token that holds the grant', function (): void {
    expect(listedToolNames($this->viewer, ['read']))->not->toContain('list-emails-tool')
        ->and(listedToolNames($this->viewer, ['read', 'email:read']))->toContain('list-emails-tool');
});

it('does not list the email tool while the email feature is off', function (): void {
    Feature::define(EmailIntegration::class, false);

    expect(listedToolNames($this->viewer, ['read', 'email:read']))->not->toContain('list-emails-tool')
        ->not->toContain('get-email-tool');
});

function fetchedEmail(User $user, string $id): array
{
    $data = [];

    RelaticleServer::actingAs($user)
        ->tool(GetEmailTool::class, ['id' => $id])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use (&$data): AssertableJson {
            $data = $json->toArray()['data'];

            return $json->etc();
        });

    return $data;
}

it('returns the body of an email the caller may read in full', function (): void {
    $email = ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::FULL, 'subject' => 'Kickoff']);

    EmailBody::query()->create(['email_id' => $email->id, 'body_html' => '<p>See you Monday</p>', 'body_text' => 'See you Monday']);
    EmailAttachment::factory()->create(['email_id' => $email->id, 'filename' => 'agenda.pdf', 'mime_type' => 'application/pdf', 'size' => 2048]);

    $data = fetchedEmail($this->viewer, $email->id);

    expect($data['access'])->toBe('full')
        ->and($data['subject'])->toBe('Kickoff')
        ->and($data['body_text'])->toBe('See you Monday')
        ->and($data['body_truncated'])->toBeFalse()
        ->and($data['attachments'])->toBe([['filename' => 'agenda.pdf', 'mime_type' => 'application/pdf', 'size' => 2048]]);
});

it('withholds the body and attachment names below full access', function (EmailPrivacyTier $tier): void {
    $email = ($this->emailFrom)($this->coworker, ['privacy_tier' => $tier]);

    EmailBody::query()->create(['email_id' => $email->id, 'body_html' => '<p>Secret</p>', 'body_text' => 'Secret']);
    EmailAttachment::factory()->create(['email_id' => $email->id, 'filename' => 'secret.pdf']);

    $data = fetchedEmail($this->viewer, $email->id);

    expect($data['access'])->toBe($tier->value)
        ->and($data['body_text'])->toBeNull()
        ->and($data['attachments'])->toBe([]);
})->with([
    'metadata only' => EmailPrivacyTier::METADATA_ONLY,
    'subject' => EmailPrivacyTier::SUBJECT,
]);

it('cuts a very large body and says so', function (): void {
    $email = ($this->emailFrom)($this->viewer);

    EmailBody::query()->create(['email_id' => $email->id, 'body_html' => '', 'body_text' => str_repeat('a', 50_000)]);

    $data = fetchedEmail($this->viewer, $email->id);

    expect(mb_strlen($data['body_text']))->toBe(20_000)
        ->and($data['body_truncated'])->toBeTrue();
});

it('answers not found for an email the caller may not see', function (): void {
    $private = ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::PRIVATE]);
    $foreign = Email::factory()->full()->create(['workspace_id' => Workspace::factory()->create()->id]);
    $draft = ($this->emailFrom)($this->viewer, ['status' => EmailStatus::DRAFT]);

    foreach ([$private->id, $foreign->id, $draft->id, 'does-not-exist'] as $id) {
        RelaticleServer::actingAs($this->viewer)
            ->tool(GetEmailTool::class, ['id' => $id])
            ->assertHasErrors(["Email with ID [{$id}] not found."]);
    }
});

it('lists the get email tool only for a token that holds the grant', function (): void {
    expect(listedToolNames($this->viewer, ['read']))->not->toContain('get-email-tool')
        ->and(listedToolNames($this->viewer, ['read', 'email:read']))->toContain('get-email-tool');
});

it('keeps a teammate internal email out of the list even when it is shared in full', function (): void {
    $email = ($this->emailFrom)($this->coworker, ['is_internal' => true, 'privacy_tier' => EmailPrivacyTier::FULL]);

    EmailShare::factory()->tier(EmailPrivacyTier::FULL)->create([
        'workspace_id' => $this->workspace->id,
        'email_id' => $email->id,
        'shared_with' => $this->viewer->id,
        'shared_by' => $this->coworker->id,
    ]);

    RelaticleServer::actingAs($this->viewer)
        ->tool(ListEmailsTool::class)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('items', [])
            ->where('has_more', false)
            ->etc());

    RelaticleServer::actingAs($this->viewer)
        ->tool(GetEmailTool::class, ['id' => $email->id])
        ->assertHasErrors(["Email with ID [{$email->id}] not found."]);
});

it('does not let a hidden internal email take a place on the page', function (): void {
    $hidden = ($this->emailFrom)($this->coworker, ['is_internal' => true, 'privacy_tier' => EmailPrivacyTier::FULL, 'sent_at' => now()->subHour()]);
    $own = ($this->emailFrom)($this->viewer, ['sent_at' => now()->subHours(2)]);

    EmailShare::factory()->tier(EmailPrivacyTier::FULL)->create([
        'workspace_id' => $this->workspace->id,
        'email_id' => $hidden->id,
        'shared_with' => $this->viewer->id,
        'shared_by' => $this->coworker->id,
    ]);

    expect(array_column(listedEmails($this->viewer, ['per_page' => 1]), 'id'))->toBe([$own->id]);
});

it('still lists the caller own internal email', function (): void {
    $email = ($this->emailFrom)($this->viewer, ['is_internal' => true]);

    expect(array_column(listedEmails($this->viewer), 'id'))->toBe([$email->id]);
});

it('returns text for an html-only body', function (): void {
    $email = ($this->emailFrom)($this->viewer);

    EmailBody::query()->create([
        'email_id' => $email->id,
        'body_text' => null,
        'body_html' => '<style>p{color:red}</style><p>Hello <b>Dana</b>,</p><p>See you &amp; the team<br>Monday</p><script>x()</script>',
    ]);

    $text = fetchedEmail($this->viewer, $email->id)['body_text'];

    expect($text)->toContain('Hello Dana,')
        ->toContain("See you & the team\nMonday")
        ->not->toContain('color:red')
        ->not->toContain('x()');
});

it('returns a null body for an email with no body row', function (): void {
    $email = ($this->emailFrom)($this->viewer);

    expect(fetchedEmail($this->viewer, $email->id)['body_text'])->toBeNull();
});

it('cuts a very large html-only body and says so', function (): void {
    $email = ($this->emailFrom)($this->viewer);

    EmailBody::query()->create(['email_id' => $email->id, 'body_text' => null, 'body_html' => '<p>'.str_repeat('a', 50_000).'</p>']);

    $data = fetchedEmail($this->viewer, $email->id);

    expect(mb_strlen($data['body_text']))->toBe(20_000)
        ->and($data['body_truncated'])->toBeTrue();
});

it('accepts numeric strings for the page arguments', function (): void {
    ($this->emailFrom)($this->viewer);

    RelaticleServer::actingAs($this->viewer)
        ->tool(ListEmailsTool::class, ['per_page' => '10', 'page' => '1'])
        ->assertOk();
});

it('lists cc recipients only where the body is shared', function (): void {
    $email = ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::METADATA_ONLY]);

    foreach ([EmailParticipantRole::TO, EmailParticipantRole::CC] as $role) {
        EmailParticipant::query()->create([
            'email_id' => $email->id,
            'email_address' => "{$role->value}@acme.test",
            'name' => null,
            'role' => $role,
        ]);
    }

    $roles = fn (): array => collect(listedEmails($this->viewer)[0]['participants'])->pluck('role')->all();

    expect($roles())->toEqualCanonicalizing(['from', 'to']);

    $email->forceFill(['privacy_tier' => EmailPrivacyTier::FULL])->save();

    expect($roles())->toEqualCanonicalizing(['from', 'to', 'cc']);
});

it('treats an empty string argument as not given', function (): void {
    ($this->emailFrom)($this->viewer, ['direction' => 'inbound']);
    ($this->emailFrom)($this->viewer, ['direction' => 'inbound']);
    ($this->emailFrom)($this->viewer, ['direction' => 'outbound']);

    $expected = array_column(listedEmails($this->viewer, ['direction' => 'inbound']), 'id');

    expect($expected)->toHaveCount(2)
        ->and(array_column(listedEmails($this->viewer, ['search' => '', 'sent_after' => '', 'thread_id' => '', 'direction' => 'inbound']), 'id'))->toEqualCanonicalizing($expected);
});

it('lists a private teammate email the viewer was shared in full', function (): void {
    $email = ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::PRIVATE]);

    EmailShare::factory()->tier(EmailPrivacyTier::FULL)->create([
        'workspace_id' => $this->workspace->id,
        'email_id' => $email->id,
        'shared_with' => $this->viewer->id,
        'shared_by' => $this->coworker->id,
    ]);

    $items = listedEmails($this->viewer);

    expect(array_column($items, 'id'))->toBe([$email->id])
        ->and($items[0]['access'])->toBe('full');
});

it('reads a teammate copy in full when the caller holds a synced copy of the message', function (): void {
    ($this->emailFrom)($this->viewer, ['rfc_message_id' => '<held@acme.test>']);
    $teammateCopy = ($this->emailFrom)($this->coworker, ['rfc_message_id' => '<held@acme.test>', 'privacy_tier' => EmailPrivacyTier::METADATA_ONLY]);

    expect(fetchedEmail($this->viewer, $teammateCopy->id)['access'])->toBe('full');
});

it('hides mail from a blocked address, even from the mailbox owner', function (): void {
    WorkspaceEmailBlocklist::factory()->blocked()->email('blocked@acme.test')->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->viewer->id,
    ]);

    $email = ($this->emailFrom)($this->viewer, [], 'blocked@acme.test');

    expect(listedEmails($this->viewer))->toBe([]);

    RelaticleServer::actingAs($this->viewer)
        ->tool(GetEmailTool::class, ['id' => $email->id])
        ->assertHasErrors(["Email with ID [{$email->id}] not found."]);
});

it('hides a teammate email whose only participant is protected', function (): void {
    WorkspaceEmailBlocklist::factory()->protected()->email('vip@acme.test')->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->viewer->id,
    ]);

    ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::FULL], 'vip@acme.test');

    expect(listedEmails($this->viewer))->toBe([]);
});

it('hides a teammate email that matches the mailbox blocklist', function (): void {
    EmailBlocklist::factory()->email('spam@acme.test')->create([
        'user_id' => $this->coworker->id,
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->coworkerAccount->getKey(),
    ]);

    ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::FULL], 'spam@acme.test');

    expect(listedEmails($this->viewer))->toBe([]);
});

it('hides a teammate email from a disconnected mailbox', function (): void {
    ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::FULL]);

    $this->coworkerAccount->delete();

    expect(listedEmails($this->viewer))->toBe([]);
});

it('does not let search guess a subject or snippet the viewer cannot see', function (): void {
    ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::METADATA_ONLY, 'subject' => 'Acquisition plan', 'snippet' => 'Budget']);
    ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::SUBJECT, 'subject' => 'Weekly sync', 'snippet' => 'Layoffs coming']);

    expect(listedEmails($this->viewer, ['search' => 'Acquisition plan']))->toBe([])
        ->and(listedEmails($this->viewer, ['search' => 'Layoffs']))->toBe([]);
});

it('filters by thread', function (): void {
    $inThread = ($this->emailFrom)($this->viewer, ['thread_id' => 'thread-one']);
    ($this->emailFrom)($this->viewer, ['thread_id' => 'thread-two']);

    expect(array_column(listedEmails($this->viewer, ['thread_id' => 'thread-one']), 'id'))->toBe([$inThread->id]);
});

it('rejects a record type without its id', function (): void {
    RelaticleServer::actingAs($this->viewer)
        ->tool(ListEmailsTool::class, ['record_type' => 'people'])
        ->assertHasErrors();
});

it('shows bcc recipients of one email to the mailbox owner only', function (): void {
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

    expect(collect(fetchedEmail($this->viewer, $own->id)['participants'])->pluck('role'))->toContain('bcc')
        ->and(collect(fetchedEmail($this->viewer, $teammates->id)['participants'])->pluck('role'))->not->toContain('bcc');
});

function emailToolData(User $user, string $tool, array $arguments = []): array
{
    $data = [];

    RelaticleServer::actingAs($user)
        ->tool($tool, $arguments)
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use (&$data): AssertableJson {
            $data = $json->toArray();

            return $json->etc();
        });

    return $data;
}

function draftArguments(ConnectedAccount $account, array $overrides = []): array
{
    return [
        'connected_account_id' => $account->getKey(),
        'to' => ['client@acme.test'],
        'subject' => 'Next steps',
        'body' => "Hi Dana,\n\nHere is the **plan**.",
        ...$overrides,
    ];
}

it('lists only the caller own mailboxes, with whether each can send', function (): void {
    $receiveOnly = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->withoutSend()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->viewer->id,
    ]));

    $items = collect(emailToolData($this->viewer, ListEmailAccountsTool::class)['items'])->keyBy('id');

    expect($items->keys()->all())->toEqualCanonicalizing([$this->viewerAccount->id, $receiveOnly->id])
        ->and($items[$this->viewerAccount->id]['email'])->toBe($this->viewerAccount->email_address)
        ->and($items[$this->viewerAccount->id]['can_send'])->toBeTrue()
        ->and($items[$receiveOnly->id]['can_send'])->toBeFalse();
});

it('saves a private draft and sends nothing', function (): void {
    $data = emailToolData($this->viewer, CreateEmailDraftTool::class, draftArguments($this->viewerAccount, ['cc' => ['boss@acme.test']]));

    $draft = Email::query()->with(['body', 'participants'])->findOrFail($data['id']);

    expect($data['status'])->toBe('draft')
        ->and($draft->status)->toBe(EmailStatus::DRAFT)
        ->and($draft->privacy_tier)->toBe(EmailPrivacyTier::PRIVATE)
        ->and($draft->creation_source)->toBe(EmailCreationSource::MCP)
        ->and($draft->user_id)->toBe($this->viewer->id)
        ->and($draft->subject)->toBe('Next steps')
        ->and($draft->body->body_html)->toContain('<strong>plan</strong>')
        ->and($draft->participants->pluck('email_address', 'role.value')->sortKeys()->all())->toBe(['cc' => 'boss@acme.test', 'to' => 'client@acme.test'])
        ->and(Email::query()->where('status', EmailStatus::QUEUED)->count())->toBe(0);
});

it('escapes raw html in a draft body', function (): void {
    $data = emailToolData($this->viewer, CreateEmailDraftTool::class, draftArguments($this->viewerAccount, [
        'body' => 'Hi <script>alert(1)</script> <img src=x onerror=alert(1)> **safe**',
    ]));

    $html = Email::query()->with('body')->findOrFail($data['id'])->body->body_html;

    expect($html)->not->toContain('<script')
        ->not->toContain('<img')
        ->toContain('<strong>safe</strong>');
});

it('drops an unsafe link and keeps a single line break in a draft body', function (): void {
    $data = emailToolData($this->viewer, CreateEmailDraftTool::class, draftArguments($this->viewerAccount, [
        'body' => "Thanks,\nDana [click](javascript:alert(1)) [site](https://acme.test)",
    ]));

    $html = Email::query()->with('body')->findOrFail($data['id'])->body->body_html;

    expect($html)->not->toContain('javascript:')
        ->toContain('href="https://acme.test"')
        ->toContain("Thanks,<br />\nDana");
});

it('adds the mailbox default signature to a draft as a signature block', function (): void {
    $signature = EmailSignature::factory()->default()->create([
        'connected_account_id' => $this->viewerAccount->id,
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->viewer->id,
        'content_html' => '<p>Dana, Acme</p>',
    ]);

    $data = emailToolData($this->viewer, CreateEmailDraftTool::class, draftArguments($this->viewerAccount));

    $html = Email::query()->with('body')->findOrFail($data['id'])->body->body_html;

    expect($html)->toContain('data-id="'.SignatureBlock::ID.'"')
        ->toContain((string) $signature->getKey())
        ->toContain(base64_encode('<p>Dana, Acme</p>'));
});

it('leaves the signature out when asked to, or when the mailbox has no default', function (): void {
    $without = emailToolData($this->viewer, CreateEmailDraftTool::class, draftArguments($this->viewerAccount));

    EmailSignature::factory()->default()->create([
        'connected_account_id' => $this->viewerAccount->id,
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->viewer->id,
    ]);

    $optedOut = emailToolData($this->viewer, CreateEmailDraftTool::class, draftArguments($this->viewerAccount, ['include_signature' => false]));

    foreach ([$without['id'], $optedOut['id']] as $id) {
        expect(Email::query()->with('body')->findOrFail($id)->body->body_html)
            ->not->toContain('data-id="'.SignatureBlock::ID.'"');
    }
});

it('refuses a draft from a mailbox the caller does not own', function (): void {
    RelaticleServer::actingAs($this->viewer)
        ->tool(CreateEmailDraftTool::class, draftArguments($this->coworkerAccount))
        ->assertHasErrors(["Mailbox with ID [{$this->coworkerAccount->id}] not found."]);

    expect(Email::query()->where('status', EmailStatus::DRAFT)->count())->toBe(0);
});

it('refuses a draft in a mailbox that is no longer connected', function (): void {
    $stale = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->error()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->viewer->id,
    ]));

    RelaticleServer::actingAs($this->viewer)
        ->tool(CreateEmailDraftTool::class, draftArguments($stale))
        ->assertHasErrors(["Mailbox with ID [{$stale->id}] not found."]);
});

it('threads a reply draft onto an email the caller may view', function (): void {
    $original = ($this->emailFrom)($this->viewer, ['rfc_message_id' => '<orig@acme.test>']);

    $data = emailToolData($this->viewer, CreateEmailDraftTool::class, draftArguments($this->viewerAccount, ['in_reply_to_email_id' => $original->id]));

    $draft = Email::query()->findOrFail($data['id']);

    expect($draft->in_reply_to)->toBe('<orig@acme.test>')
        ->and($draft->creation_source)->toBe(EmailCreationSource::REPLY);
});

it('refuses a reply draft aimed at an email the caller may not view', function (): void {
    $private = ($this->emailFrom)($this->coworker, ['privacy_tier' => EmailPrivacyTier::PRIVATE]);

    RelaticleServer::actingAs($this->viewer)
        ->tool(CreateEmailDraftTool::class, draftArguments($this->viewerAccount, ['in_reply_to_email_id' => $private->id]))
        ->assertHasErrors(["Email with ID [{$private->id}] not found."]);

    expect(Email::query()->where('status', EmailStatus::DRAFT)->count())->toBe(0);
});

it('refuses an empty draft', function (): void {
    RelaticleServer::actingAs($this->viewer)
        ->tool(CreateEmailDraftTool::class, ['connected_account_id' => $this->viewerAccount->id])
        ->assertHasErrors(['Cannot save an empty draft.']);
});

it('lists the mailbox and draft tools only for a token that holds the draft grant', function (): void {
    expect(listedToolNames($this->viewer, ['read', 'email:read']))
        ->not->toContain('create-email-draft-tool')
        ->not->toContain('list-email-accounts-tool')
        ->and(listedToolNames($this->viewer, ['read', 'email:draft']))
        ->toContain('create-email-draft-tool', 'list-email-accounts-tool')
        ->not->toContain('list-emails-tool');
});
