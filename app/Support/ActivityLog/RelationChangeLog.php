<?php

declare(strict_types=1);

namespace App\Support\ActivityLog;

use App\Enums\CrmEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final readonly class RelationChangeLog
{
    private const string TYPE = 'relation';

    /**
     * @param  array<string, string>  $labels
     */
    public function foreignKeys(Model $record, array $labels): void
    {
        foreach ($labels as $foreignKey => $label) {
            $old = $record->getOriginal($foreignKey);
            $new = $record->getAttribute($foreignKey);

            if ($old === $new) {
                continue;
            }

            $code = Str::beforeLast($foreignKey, '_id');
            $names = $this->names($record->{Str::camel($code)}()->getRelated(), [$old, $new]);

            LabelledChangeLog::write(
                $record,
                $code,
                $label,
                self::TYPE,
                $this->side($old, $names),
                $this->side($new, $names),
            );
        }
    }

    public function link(Model $record, string $relation, string $label, ?Model $removed, ?Model $added): void
    {
        LabelledChangeLog::write(
            $record,
            $relation,
            $label,
            self::TYPE,
            $this->linkSide($removed),
            $this->linkSide($added),
        );
    }

    /**
     * @param  array<int, mixed>  $keys
     * @return Collection<array-key, mixed>
     */
    private function names(Model $related, array $keys): Collection
    {
        $keys = array_values(array_filter($keys, is_string(...)));

        if ($keys === []) {
            return collect();
        }

        return $related->newQuery()
            ->withoutGlobalScopes()
            ->whereKey($keys)
            ->pluck($this->titleColumn($related), $related->getKeyName());
    }

    /**
     * @param  Collection<array-key, mixed>  $names
     * @return array{value: mixed, label: string}
     */
    private function side(mixed $key, Collection $names): array
    {
        if (! is_string($key)) {
            return ['value' => null, 'label' => ActivityValue::EMPTY];
        }

        $name = $names->get($key);

        return ['value' => $key, 'label' => is_string($name) && $name !== '' ? $name : ActivityValue::EMPTY];
    }

    /**
     * @return array{value: mixed, label: string}
     */
    private function linkSide(?Model $related): array
    {
        if (! $related instanceof Model) {
            return ['value' => null, 'label' => ActivityValue::EMPTY];
        }

        $name = $related->getAttribute($this->titleColumn($related));

        return ['value' => $related->getKey(), 'label' => is_string($name) && $name !== '' ? $name : ActivityValue::EMPTY];
    }

    private function titleColumn(Model $related): string
    {
        return CrmEntity::tryFromModel($related)?->titleColumn() ?? 'name';
    }
}
