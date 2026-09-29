<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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

        $failed->groupBy(fn (Email $email): string => $email->user_id.'|'.$email->workspace_id)
            ->each(function (Collection $senderEmails): void {
                if (! $this->shouldNotify($senderEmails)) {
                    return;
                }

                /** @var Email $first */
                $first = $senderEmails->first();

                $first->user?->notify(new EmailSendFailedNotification(
                    $first->workspace,
                    $senderEmails->count(),
                    $senderEmails->count() === 1 ? $first->subject : null,
                ));
            });
    }

    /**
     * @param  Collection<int, Email>  $emails
     */
    private function shouldNotify(Collection $emails): bool
    {
        if ($emails->contains(fn (Email $email): bool => $email->batch_id === null)) {
            return true;
        }

        // A mass send fails one job at a time; tell its sender once, not per recipient.
        return $emails->pluck('batch_id')->unique()
            ->contains(fn (string $batchId): bool => Cache::add("email-send-failed-notified:{$batchId}", true, now()->addDay()));
    }
}
