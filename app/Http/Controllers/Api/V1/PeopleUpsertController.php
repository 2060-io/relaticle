<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\People\CreatePeople;
use App\Actions\People\UpdatePeople;
use App\Http\Requests\Api\V1\UpsertPeopleRequest;
use App\Http\Resources\V1\PeopleResource;
use App\Models\People;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\ResponseFromApiResource;

/**
 * @group People
 *
 * Create a person, or update the one already holding the matched value. Returns 201 when
 * created and 200 when updated. Requires both the `create` and `update` abilities.
 */
final readonly class PeopleUpsertController
{
    #[ResponseFromApiResource(PeopleResource::class, People::class, status: 201)]
    #[BodyParam('match.field', 'string', 'Custom field code to match on.', required: true, example: 'emails')]
    #[BodyParam('match.value', 'string', 'Value to look for. Matched case-insensitively, and inside multi-value fields.', required: true, example: 'grace@navy.mil')]
    #[BodyParam('name', 'string', required: true, example: 'Grace Hopper')]
    #[BodyParam('company_id', 'string', required: false, example: null)]
    public function __invoke(
        UpsertPeopleRequest $request,
        CreatePeople $createPeople,
        UpdatePeople $updatePeople,
        #[CurrentUser] User $user,
    ): JsonResponse {
        return $request->whileHoldingMatch(function () use ($request, $createPeople, $updatePeople, $user): JsonResponse {
            $data = Arr::except($request->validated(), ['match']);
            $person = $request->matchedPerson();

            if ($person instanceof People) {
                return new PeopleResource($updatePeople->execute($user, $person, $data))->response();
            }

            return new PeopleResource($createPeople->execute($user, $data))
                ->response()
                ->setStatusCode(201);
        });
    }
}
