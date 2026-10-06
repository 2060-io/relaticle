<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Concerns\ResolvesUpsertMatch;
use App\Enums\CrmEntity;
use App\Models\Company;
use App\Models\User;

final class UpsertCompanyRequest extends BaseCrmEntityRequest
{
    use ResolvesUpsertMatch;

    public function matchedCompany(): ?Company
    {
        $matched = $this->resolveMatch();

        return $matched instanceof Company ? $matched : null;
    }

    protected function entity(): CrmEntity
    {
        return CrmEntity::Company;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function entityRules(User $user): array
    {
        return [
            ...$this->matchRules(),
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
