<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Concerns\ResolvesUpsertMatch;
use App\Enums\CrmEntity;
use App\Models\People;
use App\Models\User;
use Illuminate\Validation\Rule;

final class UpsertPeopleRequest extends BaseCrmEntityRequest
{
    use ResolvesUpsertMatch;

    public function matchedPerson(): ?People
    {
        $matched = $this->resolveMatch();

        return $matched instanceof People ? $matched : null;
    }

    protected function entity(): CrmEntity
    {
        return CrmEntity::People;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function entityRules(User $user): array
    {
        return [
            ...$this->matchRules(),
            'name' => ['required', 'string', 'max:255'],
            'company_id' => ['nullable', 'string', Rule::exists('companies', 'id')->where('workspace_id', $user->currentWorkspace->getKey())],
        ];
    }
}
