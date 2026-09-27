<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Actions\CustomFields\FindEntitiesByFieldValue;
use App\Enums\CrmEntity;
use App\Models\CustomField;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Relaticle\CustomFields\Facades\CustomFieldsType;
use Symfony\Component\HttpFoundation\Response;

trait ResolvesUpsertMatch
{
    // Enough to show a caller which records to merge without hydrating every duplicate.
    private const int REPORTED_MATCH_LIMIT = 25;

    private bool $matchResolved = false;

    private ?Model $matchedRecord = null;

    abstract protected function entity(): CrmEntity;

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function whileHoldingMatch(Closure $callback): mixed
    {
        $key = implode(':', ['upsert', $this->workspaceId(), $this->entity()->value, $this->input('match.field'), mb_strtolower($this->matchValue())]);

        try {
            return Cache::lock($key, 10)->block(5, function () use ($callback): mixed {
                // Re-resolve under the lock: a concurrent upsert may have created the record since validation.
                $this->matchResolved = false;

                return $callback();
            });
        } catch (LockTimeoutException) {
            abort(503, 'Another request is upserting this record. Retry shortly.', ['Retry-After' => '1']);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function matchRules(): array
    {
        return [
            'match' => ['required', 'array'],
            'match.field' => ['required', 'string', Rule::in($this->matchableFields()->keys()->all())],
            'match.value' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    protected function resolveMatch(string $modelClass): ?Model
    {
        if ($this->matchResolved) {
            return $this->matchedRecord;
        }

        $this->matchResolved = true;

        $field = $this->matchField();

        // Resolved before validation runs, so input the rules would reject is skipped here.
        if (! $field instanceof CustomField || ! is_string($this->input('match.value'))) {
            return null;
        }

        $matches = resolve(FindEntitiesByFieldValue::class)->execute($modelClass, $field, $this->matchValue(), self::REPORTED_MATCH_LIMIT);

        // Uniqueness is only validated on write, so records saved before the field became unique can share a value.
        if ($matches->count() > 1) {
            throw new HttpResponseException(response()->json([
                'message' => "More than one record holds this {$field->code} value. Merge the duplicates, then retry.",
                'matches' => $matches->modelKeys(),
            ], Response::HTTP_CONFLICT));
        }

        return $this->matchedRecord = $matches->first();
    }

    protected function workspaceId(): string
    {
        /** @var User $user */
        $user = $this->user();

        return (string) $user->currentWorkspace->getKey();
    }

    private function matchField(): ?CustomField
    {
        $code = $this->input('match.field');

        return is_string($code) ? $this->matchableFields()->get($code) : null;
    }

    // Stored values pass through the field type first (a link loses its scheme), so match that form.
    private function matchValue(): string
    {
        $value = $this->input('match.value');

        if (! is_string($value)) {
            return '';
        }

        $value = trim($value);
        $field = $this->matchField();

        if (! $field instanceof CustomField) {
            return $value;
        }

        return CustomFieldsType::getFieldTypeInstance($field->type)?->setValue($value) ?? $value;
    }

    /** @return Collection<string, CustomField> */
    private function matchableFields(): Collection
    {
        return once(fn (): Collection => CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->workspaceId())
            ->where('entity_type', $this->entity()->value)
            ->active()
            ->get()
            ->filter(fn (CustomField $field): bool => $field->settings->unique_per_entity_type && in_array(
                CustomFieldsType::getFieldType($field->type)?->dataType,
                FindEntitiesByFieldValue::MATCHABLE_DATA_TYPES,
                true,
            ))
            ->keyBy(fn (CustomField $field): string => (string) $field->code));
    }
}
