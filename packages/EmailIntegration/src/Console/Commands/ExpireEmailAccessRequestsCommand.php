<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Notifications\EmailAccessRequestedNotification;
use Relaticle\EmailIntegration\Notifications\EmailAccessRespondedNotification;

#[Description('Expire pending email access requests that passed their deadline')]
#[Signature('email:expire-access-requests')]
final class ExpireEmailAccessRequestsCommand extends Command
{
    public function handle(): int
    {
        $expired = 0;

        $overdue = EmailAccessRequest::query()
            ->with(['requester', 'owner'])
            ->where('status', EmailAccessRequestStatus::PENDING)
            ->where('expires_at', '<=', now())
            ->lazyById();

        foreach ($overdue as $request) {
            $this->info("Expiring access request `{$request->getKey()}`...");

            if (! $this->expire($request)) {
                continue;
            }

            EmailAccessRequestedNotification::dismissFor($request);
            $request->requester->notify(new EmailAccessRespondedNotification($request));

            $expired++;
        }

        $this->comment("Expired {$expired} access request(s).");

        return self::SUCCESS;
    }

    private function expire(EmailAccessRequest $request): bool
    {
        $decidedAt = now();

        $expired = EmailAccessRequest::query()
            ->whereKey($request->getKey())
            ->where('status', EmailAccessRequestStatus::PENDING)
            ->where('expires_at', '<=', $decidedAt)
            ->update([
                'status' => EmailAccessRequestStatus::EXPIRED->value,
                'decided_at' => $decidedAt,
            ]);

        if ($expired === 0) {
            return false;
        }

        $request->forceFill([
            'status' => EmailAccessRequestStatus::EXPIRED,
            'decided_at' => $decidedAt,
        ])->syncOriginal();

        return true;
    }
}
