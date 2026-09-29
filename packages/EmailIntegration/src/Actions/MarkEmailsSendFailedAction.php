<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Illuminate\Support\Collection;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Notifications\EmailSendFailedNotification;

final readonly class MarkEmailsSendFailedAction
{
    public function __construct(private SyncEmailBatchCountersAction $syncBatchCounters) {}

    /**
     * @param  Collection<int, Email>  $emails
     */
    public function execute(Collection $emails, string $reason): void
    {
        $failed = $emails->reject(fn (Email $email): bool => $email->status === EmailStatus::SENT);

        $failed->each(fn (Email $email): bool => $email->update([
            'status' => EmailStatus::FAILED,
            'last_error' => $reason,
        ]));

        $emails->pluck('batch_id')->filter()->unique()
            ->each(fn (string $batchId) => $this->syncBatchCounters->execute($batchId));

        $failed->whereNull('batch_id')
            ->groupBy(fn (Email $email): string => "{$email->user_id}|{$email->workspace_id}|{$email->connected_account_id}")
            ->each(function (Collection $senderEmails): void {
                /** @var Email $first */
                $first = $senderEmails->first();

                $first->user?->notify(EmailSendFailedNotification::forMailbox(
                    $first->workspace,
                    $first->connectedAccount,
                    $senderEmails->count(),
                    $senderEmails->count() === 1 ? $first->subject : null,
                ));
            });
    }
}
