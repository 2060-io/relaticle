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
        $matched = $this->resolveMatch(Company::class);

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

    // A required custom field the caller omitted is already answered by the matched
    // record, which UpdateCompany merges back in, so validation runs as an update.
    protected function existingRecord(): ?Company
    {
        return $this->matchedCompany();
    }
}
