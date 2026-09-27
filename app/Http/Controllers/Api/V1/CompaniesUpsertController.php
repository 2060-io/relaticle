<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Company\CreateCompany;
use App\Actions\Company\UpdateCompany;
use App\Http\Requests\Api\V1\UpsertCompanyRequest;
use App\Http\Resources\V1\CompanyResource;
use App\Models\Company;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\ResponseFromApiResource;

/**
 * @group Companies
 *
 * Create a company, or update the one already holding the matched value. Returns 201 when
 * created and 200 when updated. Requires both the `create` and `update` abilities.
 */
final readonly class CompaniesUpsertController
{
    #[ResponseFromApiResource(CompanyResource::class, Company::class, status: 201)]
    #[BodyParam('match.field', 'string', 'Custom field code to match on, or `name` for the company name.', required: true, example: 'name')]
    #[BodyParam('match.value', 'string', 'Value to look for. Matched case-insensitively, and inside multi-value fields.', required: true, example: 'Acme Corp')]
    #[BodyParam('name', 'string', required: true, example: 'Acme Corp')]
    public function __invoke(
        UpsertCompanyRequest $request,
        CreateCompany $createCompany,
        UpdateCompany $updateCompany,
        #[CurrentUser] User $user,
    ): JsonResponse {
        return $request->whileHoldingMatch(function () use ($request, $createCompany, $updateCompany, $user): JsonResponse {
            $data = Arr::except($request->validated(), ['match']);
            $company = $request->matchedCompany();

            if ($company instanceof Company) {
                return new CompanyResource($updateCompany->execute($user, $company, $data))->response();
            }

            return new CompanyResource($createCompany->execute($user, $data))
                ->response()
                ->setStatusCode(201);
        });
    }
}
