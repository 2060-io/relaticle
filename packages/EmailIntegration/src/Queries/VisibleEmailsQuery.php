<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Queries;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;
use Relaticle\EmailIntegration\Services\EmailSearchService;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;
use Relaticle\EmailIntegration\Services\PreferredEmailCopyService;

final readonly class VisibleEmailsQuery
{
    /** @var array<string, array{relation: string, model: class-string<Model>}> */
    private const array RECORDS = [
        'people' => ['relation' => 'people', 'model' => People::class],
        'company' => ['relation' => 'companies', 'model' => Company::class],
        'opportunity' => ['relation' => 'opportunities', 'model' => Opportunity::class],
    ];

    public function __construct(
        private EmailSearchService $search,
        private PreferredEmailCopyService $preferredCopies,
        private EmailVisibilityService $visibility,
    ) {}

    /** @return list<string> */
    public static function recordTypes(): array
    {
        return array_keys(self::RECORDS);
    }

    /**
     * @param  array{search?: string, record_type?: string, record_id?: string, direction?: string, thread_id?: string, sent_after?: string, sent_before?: string}  $filters
     * @return LengthAwarePaginator<int, Email>
     */
    public function paginate(User $viewer, array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        $query = $this->delivered();

        if (isset($filters['search'])) {
            $this->search->applyToQuery($query, $viewer, $filters['search']);
        }

        if (isset($filters['record_type'], $filters['record_id'])) {
            $this->restrictToRecord($query, $viewer, $filters['record_type'], $filters['record_id']);
        }

        $query
            ->when(isset($filters['direction']), fn (Builder $q): Builder => $q->where('direction', $filters['direction'] ?? null))
            ->when(isset($filters['thread_id']), fn (Builder $q): Builder => $q->where('thread_id', $filters['thread_id'] ?? null))
            ->when(isset($filters['sent_after']), fn (Builder $q): Builder => $q->where('sent_at', '>', Date::parse($filters['sent_after'] ?? '')))
            ->when(isset($filters['sent_before']), fn (Builder $q): Builder => $q->where('sent_at', '<', Date::parse($filters['sent_before'] ?? '')));

        return $this->preferredCopies
            ->restrictToVisiblePreferredCopies($query, $viewer)
            ->withGlobalScope('visible', new VisibleEmailScope($viewer))
            ->with(['participants', 'shares'])
            ->latest('sent_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /** @return Builder<Email> */
    private function delivered(): Builder
    {
        return Email::query()->whereIn('status', [EmailStatus::SYNCED, EmailStatus::SENT]);
    }

    /** @param  Builder<Email>  $query */
    private function restrictToRecord(Builder $query, User $viewer, string $type, string $id): void
    {
        $record = self::RECORDS[$type]['model']::query()
            ->where('workspace_id', $viewer->current_workspace_id)
            ->find($id);

        if (! $record instanceof Model || $this->visibility->hidesRecordMailbox($record)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereHas(
            self::RECORDS[$type]['relation'],
            fn (Builder $linked): Builder => $linked->whereKey($id),
        );
    }
}
