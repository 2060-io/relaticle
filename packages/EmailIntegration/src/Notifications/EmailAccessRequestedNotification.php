<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Notifications;

use App\Enums\Notifications\NotificationReason;
use App\Models\Notification as NotificationRow;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\InboxNotification;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\EmailIntegration\Livewire\EmailAccessNotificationHandler;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;

final class EmailAccessRequestedNotification extends InboxNotification
{
    public function __construct(public readonly EmailAccessRequest $request) {}

    public function workspace(): Workspace
    {
        return $this->request->email->workspace;
    }

    public function subject(): EmailAccessRequest
    {
        return $this->request;
    }

    public function actor(): User
    {
        return $this->request->requester;
    }

    public function reason(): NotificationReason
    {
        return NotificationReason::Requested;
    }

    /** @return array<string, mixed> */
    public function facts(): array
    {
        return [
            'email_subject' => $this->request->email->subject,
            'email_id' => $this->request->email_id,
        ];
    }

    public static function present(NotificationRow $row): FilamentNotification
    {
        $emailSubject = data_get($row->data, 'email_subject');
        $requesterName = $row->actor?->name;

        return FilamentNotification::make((string) $row->getKey())
            ->title($requesterName === null
                ? __('filament/notifications/email-access-requested.title_without_requester')
                : __('filament/notifications/email-access-requested.title', ['name' => e($requesterName)]))
            ->body(is_string($emailSubject) && $emailSubject !== '' ? e($emailSubject) : __('filament/pages/email-inbox.subject.none'))
            ->warning()
            ->icon(Heroicon::OutlinedKey)
            ->actions([
                self::action('viewEmail', 'view', 'info', 'open-email-from-access-request', ['emailId' => data_get($row->data, 'email_id')]),
                self::action('accept', 'accept', 'success', 'approve-email-access-request', ['requestId' => $row->subject_id]),
                self::action('decline', 'decline', 'warning', 'deny-email-access-request', ['requestId' => $row->subject_id]),
            ]);
    }

    /**
     * Delete the owner's bell row for this request. Same outcome as the
     * notification close (X) action: the row is removed, not marked read.
     */
    public static function dismissFor(EmailAccessRequest $request): void
    {
        $owner = $request->owner ?? User::query()->whereKey($request->owner_id)->first();

        if (! $owner instanceof User) {
            return;
        }

        $owner->notifications()
            ->where('type', self::class)
            ->where(fn (Builder $rows): Builder => $rows
                ->where(fn (Builder $current): Builder => $current
                    ->where('subject_type', $request->getMorphClass())
                    ->where('subject_id', $request->getKey()))
                ->orWhere('data->viewData->request_id', (string) $request->getKey()))
            ->delete();
    }

    /** @param  array<string, mixed>  $eventData */
    private static function action(string $name, string $label, string $color, string $event, array $eventData): Action
    {
        return Action::make($name)
            ->label(__("filament/notifications/email-access-requested.actions.{$label}"))
            ->link()
            ->color($color)
            ->dispatchTo(EmailAccessNotificationHandler::LIVEWIRE_ALIAS, $event)
            ->eventData($eventData);
    }
}
