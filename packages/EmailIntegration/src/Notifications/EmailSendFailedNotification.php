<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Notifications;

use App\Enums\Notifications\NotificationReason;
use App\Models\Notification as NotificationRow;
use App\Models\Workspace;
use App\Notifications\InboxNotification;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Relaticle\EmailIntegration\Enums\SendFailureRemedy;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final class EmailSendFailedNotification extends InboxNotification
{
    private const string BATCH_KEY_PREFIX = 'send-failed:batch:';

    public function __construct(
        private readonly Workspace $workspace,
        private readonly ?ConnectedAccount $account,
        private readonly int $count,
        private readonly ?string $emailSubject,
        private readonly SendFailureRemedy $remedy,
        private readonly string $groupKey,
    ) {}

    public static function forMailbox(Workspace $workspace, ?ConnectedAccount $account, int $count, ?string $subject, string $groupKey): self
    {
        return new self($workspace, $account, $count, $subject, SendFailureRemedy::for($workspace, $account), $groupKey);
    }

    public static function batchGroupKey(string $batchId): string
    {
        return self::BATCH_KEY_PREFIX.$batchId;
    }

    public static function emailGroupKey(string $emailId): string
    {
        return "send-failed:email:{$emailId}";
    }

    public function workspace(): Workspace
    {
        return $this->workspace;
    }

    public function subject(): Model
    {
        return $this->account ?? $this->workspace;
    }

    public function reason(): NotificationReason
    {
        return NotificationReason::System;
    }

    public function groupKey(): string
    {
        return $this->groupKey;
    }

    /** @return array<string, mixed> */
    public function facts(): array
    {
        return [
            'count' => $this->count,
            'email_subject' => $this->emailSubject,
            'remedy' => $this->remedy->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    public function mergeFacts(array $existing): array
    {
        if (! str_starts_with($this->groupKey, self::BATCH_KEY_PREFIX)) {
            return $this->facts();
        }

        return [
            ...$this->facts(),
            'count' => (int) ($existing['count'] ?? 0) + $this->count,
            'email_subject' => null,
        ];
    }

    public static function present(NotificationRow $row): FilamentNotification
    {
        $remedy = SendFailureRemedy::from((string) data_get($row->data, 'remedy'));
        $count = (int) data_get($row->data, 'count');
        $workspace = $row->workspace;

        return FilamentNotification::make((string) $row->getKey())
            ->danger()
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->title(trans_choice('filament/notifications/email-send-failed.title', $count, ['count' => $count]))
            ->body(self::body($remedy, $count, data_get($row->data, 'email_subject'), $workspace?->name))
            ->actions($workspace instanceof Workspace ? [
                Action::make('viewFailed')
                    ->label(__("filament/notifications/email-send-failed.{$remedy->value}.action"))
                    ->url($remedy->url($workspace))
                    ->markAsRead(),
            ] : []);
    }

    private static function body(SendFailureRemedy $remedy, int $count, mixed $emailSubject, ?string $workspaceName): string
    {
        $parameters = ['workspace' => $workspaceName ?? ''];

        if ($count !== 1) {
            return __("filament/notifications/email-send-failed.{$remedy->value}.body_many", $parameters);
        }

        return __("filament/notifications/email-send-failed.{$remedy->value}.body_one", [
            ...$parameters,
            'subject' => is_string($emailSubject) && $emailSubject !== '' ? $emailSubject : __('filament/pages/email-inbox.subject.none'),
        ]);
    }
}
