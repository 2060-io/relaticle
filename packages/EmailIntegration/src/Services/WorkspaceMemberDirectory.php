<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class WorkspaceMemberDirectory
{
    /** @var array<string, array<string, array{name: string, avatar: string|null}>> */
    private array $membersByWorkspace = [];

    /**
     * @return array{name: string, avatar: string|null}|null
     */
    public function find(string $workspaceId, string $email): ?array
    {
        $this->load($workspaceId);

        return $this->membersByWorkspace[$workspaceId][Str::lower($email)] ?? null;
    }

    private function load(string $workspaceId): void
    {
        if (array_key_exists($workspaceId, $this->membersByWorkspace)) {
            return;
        }

        $members = [];

        User::query()
            ->whereHas(
                'workspaces',
                fn (Builder $query): Builder => $query->where('workspaces.id', $workspaceId),
            )
            ->get()
            ->each(function (User $user) use (&$members): void {
                $email = Str::lower(trim($user->email));

                if ($email === '' || trim($user->name) === '') {
                    return;
                }

                $members[$email] = [
                    'name' => trim($user->name),
                    'avatar' => $user->profile_photo_url,
                ];
            });

        $this->membersByWorkspace[$workspaceId] = $members;
    }
}
