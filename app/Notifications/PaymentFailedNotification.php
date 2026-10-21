<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\Notifications\NotificationReason;
use App\Enums\Plan;
use App\Filament\Pages\Billing;
use App\Models\Notification as NotificationRow;
use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;

final class PaymentFailedNotification extends InboxNotification
{
    public function __construct(private readonly Workspace $workspace) {}

    public function workspace(): Workspace
    {
        return $this->workspace;
    }

    public function subject(): Workspace
    {
        return $this->workspace;
    }

    public function reason(): NotificationReason
    {
        return NotificationReason::System;
    }

    public function groupKey(): string
    {
        return "payment-failed:{$this->workspace->getKey()}";
    }

    /** @return array<string, mixed> */
    public function facts(): array
    {
        return ['enterprise' => $this->workspace->plan === Plan::Enterprise];
    }

    public static function present(NotificationRow $row): FilamentNotification
    {
        $workspace = $row->workspace;

        return FilamentNotification::make((string) $row->getKey())
            ->title(__('billing.payment_failed.notification_title', ['workspace' => $workspace->name ?? '']))
            ->body(data_get($row->data, 'enterprise') === true
                ? __('billing.enterprise.previous_subscription_past_due')
                : __('billing.payment_failed.notification_body'))
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->iconColor('danger')
            ->actions($workspace instanceof Workspace ? [
                Action::make('billing')
                    ->button()
                    ->label(__('billing.payment_failed.notification_action'))
                    ->url(Billing::getUrl(panel: 'app', tenant: $workspace))
                    ->markAsRead(),
            ] : []);
    }
}
