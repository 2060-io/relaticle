<?php

declare(strict_types=1);

use App\Features\Documentation;
use App\Livewire\App\Workspaces\InviteWorkspaceMembers;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\RoleOptions;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Laravel\Pennant\Feature;

mutates(RoleOptions::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->actingAs($this->owner);
    Filament::setTenant($this->workspace);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

function readRolesHelpAction(Workspace $workspace): Action
{
    $component = livewire(InviteWorkspaceMembers::class, ['workspace' => $workspace])->instance();

    $action = collect(RoleOptions::compareAction()->livewire($component)->getExtraModalFooterActions())
        ->first(fn (Action $action): bool => $action->getName() === 'readRolesHelp');

    expect($action)->toBeInstanceOf(Action::class);

    return $action;
}

test('the invite form renders without the help routes when the documentation feature is off', function (): void {
    Feature::define(Documentation::class, false);

    livewire(InviteWorkspaceMembers::class, ['workspace' => $this->workspace])
        ->assertSuccessful()
        ->mountAction('invitePeople')
        ->assertSuccessful();

    expect(readRolesHelpAction($this->workspace)->isVisible())->toBeFalse();
});

test('the compare-roles dialog links to the help centre when the documentation feature is on', function (): void {
    Feature::define(Documentation::class, true);

    $action = readRolesHelpAction($this->workspace);

    expect($action->isVisible())->toBeTrue()
        ->and($action->getUrl())->toEndWith('/help/workspace/manage-members-and-roles');
});
