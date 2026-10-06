<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Relaticle\EmailIntegration\Models\EmailTemplate;

final readonly class DeleteEmailTemplatesAction
{
    /**
     * @param  Collection<int, EmailTemplate>  $templates
     * @return int Number of templates deleted.
     */
    public function execute(User $user, Collection $templates): int
    {
        return $templates
            ->filter(fn (EmailTemplate $template): bool => $user->can('delete', $template))
            ->each->delete()
            ->count();
    }
}
