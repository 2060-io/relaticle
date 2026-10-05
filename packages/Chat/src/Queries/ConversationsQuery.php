<?php

declare(strict_types=1);

namespace Relaticle\Chat\Queries;

use App\Models\User;
use App\Support\LikePattern;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Support\TitleSanitizer;
use stdClass;

final readonly class ConversationsQuery
{
    private const array COLUMNS = ['id', 'title', 'created_at', 'updated_at'];

    public function find(User $user, string $conversationId): ?stdClass
    {
        return AgentConversation::query()
            ->ownedBy($user)
            ->whereKey($conversationId)
            ->toBase()
            ->first(self::COLUMNS);
    }

    /** @return Collection<int, stdClass> */
    public function recent(User $user, int $limit = 50): Collection
    {
        return $this->withCleanTitles(
            AgentConversation::query()
                ->ownedBy($user)
                ->latest('updated_at')
                ->limit($limit)
                ->toBase()
                ->get(self::COLUMNS),
        );
    }

    /** @return Collection<int, stdClass> */
    public function search(User $user, string $term): Collection
    {
        $term = trim($term);

        if ($term === '') {
            return collect();
        }

        $needle = '%'.LikePattern::escape($term).'%';

        return $this->withCleanTitles(
            AgentConversation::query()
                ->ownedBy($user)
                ->where(function (Builder $conversation) use ($needle): void {
                    $conversation->where('title', 'ilike', $needle)
                        ->orWhereHas('messages', fn (Builder $message): Builder => $message->withoutSynthetic()->where('content', 'ilike', $needle));
                })
                ->latest('updated_at')
                ->limit(50)
                ->toBase()
                ->get(self::COLUMNS),
        );
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return Collection<int, stdClass>
     */
    private function withCleanTitles(Collection $rows): Collection
    {
        return $rows->map(function (stdClass $row): stdClass {
            $row->title = TitleSanitizer::clean((string) $row->title);

            return $row;
        });
    }
}
