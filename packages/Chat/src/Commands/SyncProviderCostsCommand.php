<?php

declare(strict_types=1);

namespace Relaticle\Chat\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Relaticle\Chat\Models\AiProviderCost;
use Throwable;

#[Description('Store what Anthropic and OpenAI billed for the last 7 days')]
#[Signature('ai:sync-provider-costs')]
final class SyncProviderCostsCommand extends Command
{
    private const int DAYS = 7;

    public function handle(): int
    {
        $start = today()->subDays(self::DAYS);
        $end = today()->addDay();

        foreach (['anthropic' => $this->anthropic(...), 'openai' => $this->openAi(...)] as $provider => $fetch) {
            if (blank(config("services.{$provider}.admin_key"))) {
                $this->comment("Skipping {$provider}: no admin key.");

                continue;
            }

            $this->info("Fetching {$provider} costs...");

            try {
                $this->store($provider, $fetch($start, $end));
            } catch (Throwable $exception) {
                Log::warning("Provider cost sync failed for {$provider}", ['exception' => $exception->getMessage()]);
                $this->warn("Could not fetch {$provider}: {$exception->getMessage()}");
            }
        }

        $this->comment('Provider costs synced.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, int>
     */
    private function anthropic(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $workspaceId = config('services.anthropic.workspace_id') ?: null;
        $byDay = [];
        $page = null;

        do {
            $response = Http::withHeaders([
                'x-api-key' => (string) config('services.anthropic.admin_key'),
                'anthropic-version' => '2023-06-01',
            ])->get('https://api.anthropic.com/v1/organizations/cost_report', array_filter([
                'starting_at' => $start->toIso8601ZuluString(),
                'ending_at' => $end->toIso8601ZuluString(),
                'bucket_width' => '1d',
                'group_by[]' => 'workspace_id',
                'limit' => 31,
                'page' => $page,
            ], fn (mixed $value): bool => $value !== null))->throw();

            foreach ((array) $response->json('data', []) as $bucket) {
                $day = mb_substr((string) ($bucket['starting_at'] ?? ''), 0, 10);
                $byDay[$day] ??= 0;

                foreach ((array) ($bucket['results'] ?? []) as $result) {
                    if (($result['workspace_id'] ?? null) === $workspaceId) {
                        $byDay[$day] += (int) round(((float) ($result['amount'] ?? 0)) * 10_000);
                    }
                }
            }

            $page = $response->json('has_more') === true ? $response->json('next_page') : null;
        } while (is_string($page));

        return $byDay;
    }

    /**
     * @return array<string, int>
     */
    private function openAi(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $projectId = config('services.openai.project_id');
        $byDay = [];
        $page = null;

        do {
            $response = Http::withToken((string) config('services.openai.admin_key'))
                ->get('https://api.openai.com/v1/organization/costs', array_filter([
                    'start_time' => $start->getTimestamp(),
                    'end_time' => $end->getTimestamp(),
                    'bucket_width' => '1d',
                    'limit' => 31,
                    'project_ids[]' => is_string($projectId) && $projectId !== '' ? $projectId : null,
                    'page' => $page,
                ], fn (mixed $value): bool => $value !== null))->throw();

            foreach ((array) $response->json('data', []) as $bucket) {
                $day = CarbonImmutable::createFromTimestampUTC((int) ($bucket['start_time'] ?? 0))->toDateString();
                $byDay[$day] ??= 0;

                foreach ((array) ($bucket['results'] ?? []) as $result) {
                    $byDay[$day] += (int) round(((float) ($result['amount']['value'] ?? 0)) * 1_000_000);
                }
            }

            $page = $response->json('has_more') === true ? $response->json('next_page') : null;
        } while (is_string($page));

        return $byDay;
    }

    /**
     * @param  array<string, int>  $byDay
     */
    private function store(string $provider, array $byDay): void
    {
        $fetchedAt = now();

        AiProviderCost::query()->upsert(
            array_map(fn (string $day, int $micros): array => [
                'provider' => $provider,
                'date' => $day,
                'amount_micros' => $micros,
                'fetched_at' => $fetchedAt,
            ], array_keys($byDay), $byDay),
            ['provider', 'date'],
            ['amount_micros', 'fetched_at'],
        );
    }
}
