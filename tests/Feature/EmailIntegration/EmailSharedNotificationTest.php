<?php

declare(strict_types=1);

use App\Enums\Notifications\NotificationReason;
use App\Filament\Pages\Dashboard;
use App\Models\Notification as NotificationRow;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Relaticle\EmailIntegration\Actions\UpdateEmailSharingAction;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Notifications\EmailSharedNotification;
use Relaticle\EmailIntegration\Services\EmailSharingService;
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

it('sends a Participants-only share without the subject in the bell or the mail', function (): void {
    saveSharing([$this->alice], EmailPrivacyTier::METADATA_ONLY);

    $row = $this->alice->notifications()->where('type', EmailSharedNotification::class)->sole();

    $this->actingAs($this->alice);
    $presented = EmailSharedNotification::present($row);
    $mail = new EmailSharedNotification($this->email, $this->owner, $this->alice, EmailPrivacyTier::METADATA_ONLY)->toMail($this->alice);

    expect($row->data['email_subject'])->toBeNull()
        ->and($presented->getTitle())->toBe($this->owner->name.' shared an email with you')
        ->and($presented->getBody())->toBeEmpty()
        ->and($presented->getActions())->toBeEmpty()
        ->and((string) $mail->render())->not->toContain('Renewal terms')
        ->and($mail->actionText)->toBe('Open Relaticle')
        ->and($mail->actionUrl)->not->toContain('email=');
});

it('sends nothing for a Private share', function (): void {
    Notification::fake();

    saveSharing([$this->alice], EmailPrivacyTier::PRIVATE);

    Notification::assertNothingSent();
});

it('sends nothing when the share is revoked before the notice goes out', function (): void {
    $notification = new EmailSharedNotification($this->email, $this->owner, $this->alice, EmailPrivacyTier::FULL);
    $sharing = resolve(EmailSharingService::class);

    $sharing->shareEmail($this->email, $this->owner, $this->alice, EmailPrivacyTier::FULL);
    expect($notification->shouldSend($this->alice, 'mail'))->toBeTrue();

    $sharing->revokeShare($this->email, $this->alice);

    expect($notification->shouldSend($this->alice, 'mail'))->toBeFalse()
        ->and($notification->shouldSend($this->alice, 'database'))->toBeFalse();
});

it('gives a Full share a reader link in the bell and the mail', function (): void {
    saveSharing([$this->alice], EmailPrivacyTier::FULL);

    $row = $this->alice->notifications()->where('type', EmailSharedNotification::class)->sole();

    $this->actingAs($this->alice);
    $presented = EmailSharedNotification::present($row);
    $mail = new EmailSharedNotification($this->email, $this->owner, $this->alice, EmailPrivacyTier::FULL)->toMail($this->alice);
    $link = Dashboard::getUrl(['email' => $this->email->getKey()], panel: 'app', tenant: $this->workspace);

    expect($presented->getBody())->toBe('Renewal terms')
        ->and($presented->getActions())->toHaveCount(1)
        ->and($presented->getActions()[0]->getUrl())->toBe($link)
        ->and($mail->actionText)->toBe('View email')
        ->and($mail->actionUrl)->toBe($link);
});

it('renders Markdown in the subject and the sharer name as text', function (): void {
    $this->owner->update(['name' => '[Boss](https://evil.example/name)']);
    $this->email->update(['subject' => '[x](https://evil.example) and www.evil.example and bad@evil.example']);
    saveSharing([$this->alice], EmailPrivacyTier::FULL);

    $html = (string) new EmailSharedNotification($this->email->fresh(), $this->owner->fresh(), $this->alice, EmailPrivacyTier::FULL)->toMail($this->alice)->render();

    expect($html)->not->toContain('<a href="https://evil.example')
        ->not->toContain('<a href="http://www.evil.example')
        ->not->toContain('<a href="mailto:bad@evil.example')
        ->toContain('[x](https://evil.example)');
});

it('leaves the email tier unchanged when a member is rejected', function (): void {
    $outsider = User::factory()->withWorkspace()->create();

    expect(fn () => saveSharing([$outsider]))->toThrow(HttpException::class);

    expect($this->email->fresh()->privacy_tier)->toBe(EmailPrivacyTier::PRIVATE);
});

it('does not notify a member who already had access through another share', function (): void {
    resolve(EmailSharingService::class)->shareEmail($this->email, $this->bob, $this->alice, EmailPrivacyTier::FULL);

    Notification::fake();

    saveSharing([$this->alice]);

    Notification::assertNothingSent();
});
