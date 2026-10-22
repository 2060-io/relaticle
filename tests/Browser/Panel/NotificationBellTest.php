<?php

declare(strict_types=1);

use App\Actions\Task\NotifyTaskAssignees;
use App\Features\EmailIntegration;
use App\Livewire\App\AppDatabaseNotifications;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Pennant\Feature;
use Pest\Browser\Api\AwaitableWebpage;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;

mutates(AppDatabaseNotifications::class);

const INBOX_TRIGGER = '.fi-sidebar-notifications-btn';
const INBOX_PANEL = '.fi-inbox-panel';

function notifiedOwner(string ...$taskTitles): array
{
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->ownedWorkspaces()->first();
    $teammate = User::factory()->create(['name' => 'Dana Reyes']);
    $workspace->users()->attach($teammate, ['role' => 'member']);

    foreach ($taskTitles as $title) {
        $task = Task::factory()->for($workspace)->create(['title' => $title]);

        resolve(NotifyTaskAssignees::class)->execute($task, [$owner->getKey()], $teammate);
    }

    return [$owner, $workspace, $teammate];
}

function openInbox(User $owner, Workspace $workspace): AwaitableWebpage
{
    return loginViaBrowser($owner)
        ->assertPathIs("/app/{$workspace->slug}")
        ->assertScript('!! (window.Alpine && window.Livewire)', true)
        ->wait(1)
        ->click(INBOX_TRIGGER)
        ->assertVisible(INBOX_PANEL);
}

it('keeps a notification in the open bell instead of dismissing it after a few seconds', function (): void {
    $this->withVite();

    [$user, $workspace] = notifiedOwner('Send the renewal quote');

    openInbox($user, $workspace)
        ->assertSee('Send the renewal quote')
        ->wait(8)
        ->assertSee('Send the renewal quote')
        ->assertNoJavaScriptErrors();

    expect($user->notifications()->count())->toBe(1);
});

it('opens the panel with the notification under Today and focuses the active tab', function (): void {
    $this->withVite();

    [$user, $workspace] = notifiedOwner('Send the renewal quote');

    openInbox($user, $workspace)
        ->assertSeeIn(INBOX_PANEL.' section', 'Today')
        ->assertSeeIn(INBOX_PANEL.' li[data-inbox-key]', 'Send the renewal quote')
        ->assertScript('document.activeElement?.id', 'inbox-tab-notifications')
        ->assertNoJavaScriptErrors();
});

it('archives a notification from its hover control and moves focus to the next row', function (): void {
    $this->withVite();

    [$user, $workspace] = notifiedOwner('Send the renewal quote', 'Book the kickoff call');

    $archived = INBOX_PANEL.' li[data-inbox-key]:has-text("Book the kickoff call")';

    openInbox($user, $workspace)
        ->assertVisible($archived)
        ->hover($archived)
        ->click($archived.' button[aria-label="Archive"]')
        ->wait(1)
        ->assertMissing($archived)
        ->assertSeeIn(INBOX_PANEL.' li[data-inbox-key]', 'Send the renewal quote')
        ->assertScript("document.activeElement?.closest('li[data-inbox-key]')?.textContent.includes('Send the renewal quote')", true)
        ->assertNoJavaScriptErrors();

    expect($user->notifications()->count())->toBe(2)
        ->and($user->notifications()->whereNull('archived_at')->count())->toBe(1);
});

it('falls back to the active tab when the last notification is archived', function (): void {
    $this->withVite();

    [$user, $workspace] = notifiedOwner('Send the renewal quote');

    $row = INBOX_PANEL.' li[data-inbox-key]';

    openInbox($user, $workspace)
        ->hover($row)
        ->click($row.' button[aria-label="Archive"]')
        ->wait(1)
        ->assertMissing($row)
        ->assertSeeIn(INBOX_PANEL, 'You are all caught up')
        ->assertScript('document.activeElement?.id', 'inbox-tab-notifications')
        ->assertNoJavaScriptErrors();
});

it('approves an email access request from the Requests tab and shows the outcome', function (): void {
    $this->withVite();
    Feature::for(null)->activate(EmailIntegration::class);

    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->ownedWorkspaces()->first();
    $requester = User::factory()->create(['name' => 'Dana Whitfield', 'current_workspace_id' => $workspace->id]);
    $workspace->users()->attach($requester, ['role' => 'member']);

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
    ]));
    $email = Email::factory()->private()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'connected_account_id' => $account->getKey(),
        'subject' => 'Confidential Thread',
    ]);
    $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
        'requester_id' => $requester->id,
        'owner_id' => $owner->id,
        'email_id' => $email->getKey(),
        'workspace_id' => $workspace->id,
        'expires_at' => now()->addDays(30),
    ]);

    openInbox($owner, $workspace)
        ->click(INBOX_PANEL.' [role=tab]:nth-child(2)')
        ->wait(0.3)
        ->assertSeeIn(INBOX_PANEL.' li[data-inbox-key]', 'Dana Whitfield requested email access')
        ->click(INBOX_PANEL.' [data-inbox-approve]')
        ->wait(1)
        ->assertMissing(INBOX_PANEL.' [data-inbox-approve]')
        ->assertSeeIn(INBOX_PANEL.' li[data-inbox-key]', 'Approved')
        ->assertNoJavaScriptErrors();

    expect($request->refresh()->status)->toBe(EmailAccessRequestStatus::APPROVED);
});

it('closes the panel on Escape and returns focus to the trigger', function (): void {
    $this->withVite();

    [$user, $workspace] = notifiedOwner('Send the renewal quote');

    openInbox($user, $workspace)
        ->wait(0.5)
        ->keys(INBOX_PANEL, 'Escape')
        ->wait(1)
        ->assertMissing(INBOX_PANEL)
        ->assertScript('document.activeElement?.classList.contains("fi-sidebar-notifications-btn")', true)
        ->assertNoJavaScriptErrors();
});
