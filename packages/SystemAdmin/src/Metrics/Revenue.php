<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Metrics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Subscription;
use Relaticle\SystemAdmin\Metrics\Scopes\CountsTowardMrr;
use Throwable;

final readonly class Revenue
{
    public function monthlyMicros(?CarbonImmutable $asOf = null): ?int
    {
        $subscriptions = Subscription::query()
            ->withGlobalScope(CountsTowardMrr::class, new CountsTowardMrr($asOf))
            ->with('owner')
            ->get();

        $total = 0;

        foreach ($subscriptions as $subscription) {
            $amount = $this->monthlyAmountMicros($subscription);

            if ($amount === null) {
                return null;
            }

            $total += $amount;
        }

        return $total;
    }

    private function monthlyAmountMicros(Subscription $subscription): ?int
    {
        return Cache::remember("sysadmin.mrr.{$subscription->stripe_id}", now()->addDay(), function () use ($subscription): ?int {
            try {
                $stripe = $subscription->asStripeSubscription(['latest_invoice']);
            } catch (Throwable $exception) {
                report($exception);

                return null;
            }

            $invoice = $stripe->latest_invoice;

            if ($invoice === null || is_string($invoice)) {
                return 0;
            }

            $recurring = $stripe->items->data[0]->price->recurring ?? null;
            $count = max(1, (int) ($recurring->interval_count ?? 1));
            $months = match ($recurring->interval ?? 'month') {
                'year' => 12 * $count,
                default => $count,
            };

            return intdiv(((int) $invoice->total_excluding_tax) * 10_000, $months);
        });
    }
}
