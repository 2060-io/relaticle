<?php

declare(strict_types=1);

use App\Enums\Notifications\NotificationReason;
use App\Models\Notification as NotificationRow;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Relaticle\EmailIntegration\Actions\UpdateEmailSharingAction;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Notifications\EmailSharedNotification;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(UpdateEmailSharingAction::class, EmailSharedNotification::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->actingAs($this->owner);
    $this->workspace = $this->owner->currentWorkspace;
    Filament::setTenant($this->workspace);

    $this->alice = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->bob = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach([$this->alice->id => ['role' => 'member'], $this->bob->id => ['role' => 'member']]);

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
    ]));

    $this->email = Email::factory()->private()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $account->getKey(),
        'subject' => 'Renewal terms',
    ]);
});

function saveSharing(array $members, EmailPrivacyTier $tier = EmailPrivacyTier::FULL): void
{
    resolve(UpdateEmailSharingAction::class)->execute(
        test()->email,
        test()->owner,
        EmailPrivacyTier::METADATA_ONLY,
        array_map(fn (User $member): array => ['shared_with' => $member->getKey(), 'tier' => $tier->value], $members),
    );
}

it('notifies a member the email is newly shared with, once', function (): void {
    Notification::fake();

    saveSharing([$this->alice]);

    Notification::assertSentToTimes($this->alice, EmailSharedNotification::class, 1);
    Notification::assertNotSentTo($this->bob, EmailSharedNotification::class);
});

it('notifies nobody when a save keeps the existing shares', function (): void {
    saveSharing([$this->alice]);

    Notification::fake();

    saveSharing([$this->alice]);

    Notification::assertNothingSent();
});

it('notifies only the member added on a later save', function (): void {
    saveSharing([$this->alice]);

    Notification::fake();

    saveSharing([$this->alice, $this->bob]);

    Notification::assertSentToTimes($this->bob, EmailSharedNotification::class, 1);
    Notification::assertNotSentTo($this->alice, EmailSharedNotification::class);
});

it('notifies a member once when listed in two rows', function (): void {
    Notification::fake();

    resolve(UpdateEmailSharingAction::class)->execute($this->email, $this->owner, EmailPrivacyTier::METADATA_ONLY, [
        ['shared_with' => [$this->alice->getKey()], 'tier' => EmailPrivacyTier::FULL->value],
        ['shared_with' => $this->alice->getKey(), 'tier' => EmailPrivacyTier::SUBJECT->value],
    ]);

    Notification::assertSentToTimes($this->alice, EmailSharedNotification::class, 1);
});

it('never notifies the sharer', function (): void {
    Notification::fake();

    saveSharing([$this->owner, $this->alice]);

    Notification::assertNotSentTo($this->owner, EmailSharedNotification::class);
    Notification::assertSentToTimes($this->alice, EmailSharedNotification::class, 1);
});

it('notifies nobody and keeps the old shares when a member is outside the workspace', function (): void {
    saveSharing([$this->alice]);

    $outsider = User::factory()->withWorkspace()->create();

    Notification::fake();

    expect(fn () => saveSharing([$this->bob, $outsider]))->toThrow(HttpException::class);

    Notification::assertNothingSent();
    $this->assertDatabaseHas('email_shares', ['email_id' => $this->email->getKey(), 'shared_with' => $this->alice->getKey()]);
    $this->assertDatabaseMissing('email_shares', ['email_id' => $this->email->getKey(), 'shared_with' => $this->bob->getKey()]);
});

it('writes the row in the email workspace with the sharer as actor', function (): void {
    saveSharing([$this->alice], EmailPrivacyTier::SUBJECT);

    $row = $this->alice->notifications()->where('type', EmailSharedNotification::class)->sole();

    expect($row)->toBeInstanceOf(NotificationRow::class)
        ->and($row->workspace_id)->toBe($this->workspace->getKey())
        ->and($row->actor_id)->toBe($this->owner->getKey())
        ->and($row->subject_id)->toBe($this->email->getKey())
        ->and($row->reason)->toBe(NotificationReason::Shared)
        ->and($row->data)->toBe(['email_subject' => 'Renewal terms', 'tier' => EmailPrivacyTier::SUBJECT->value]);
});

it('turning off the in-app channel leaves the mail', function (): void {
    $this->alice->update(['notification_preferences' => ['email_shared' => ['in_app' => false]]]);

    Notification::fake();

    saveSharing([$this->alice]);

    Notification::assertSentTo($this->alice, EmailSharedNotification::class, fn (EmailSharedNotification $notification, array $channels): bool => array_values($channels) === ['mail']);
});

it('turning off the email channel leaves the in-app row', function (): void {
    $this->alice->update(['notification_preferences' => ['email_shared' => ['email' => false]]]);

    Notification::fake();

    saveSharing([$this->alice]);

    Notification::assertSentTo($this->alice, EmailSharedNotification::class, fn (EmailSharedNotification $notification, array $channels): bool => $channels === ['database']);
});

it('mails a button to the app and renders the row for the recipient', function (): void {
    saveSharing([$this->alice]);

    $row = $this->alice->notifications()->where('type', EmailSharedNotification::class)->sole();
    $notification = EmailSharedNotification::present($row);

    $mail = new EmailSharedNotification($this->email, $this->owner, EmailPrivacyTier::FULL)->toMail($this->alice);

    expect($notification->getTitle())->toContain($this->owner->name)
        ->and($notification->getBody())->toBe('Renewal terms')
        ->and($mail->actionText)->toBe('Open Relaticle')
        ->and($mail->actionUrl)->toBe(EmailInboxPage::getUrl(panel: 'app', tenant: $this->workspace));
});
