<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Pages\CreateWorkspace;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\WorkspacePolicy;

mutates(WorkspacePolicy::class, CreateWorkspace::class);

it('lets any user create a workspace while creation_admins_only is off', function (): void {
    config()->set('relaticle.workspaces.creation_admins_only', false);

    $user = User::factory()->create();

    expect($user->can('create', Workspace::class))->toBeTrue();
});

it('blocks a user with no workspace when creation_admins_only is on', function (): void {
    config()->set('relaticle.workspaces.creation_admins_only', true);

    $user = User::factory()->create();

    expect($user->can('create', Workspace::class))->toBeFalse();
});

it('lets a workspace owner create another one when creation_admins_only is on', function (): void {
    config()->set('relaticle.workspaces.creation_admins_only', true);

    $user = User::factory()->withWorkspace()->create();

    expect($user->can('create', Workspace::class))->toBeTrue();
});

it('lets an admin member create a workspace when creation_admins_only is on', function (): void {
    config()->set('relaticle.workspaces.creation_admins_only', true);

    $owner = User::factory()->withWorkspace()->create();
    $member = User::factory()->create();
    $owner->currentWorkspace->users()->attach($member, ['role' => WorkspaceRole::Admin->value]);

    expect($member->fresh()->can('create', Workspace::class))->toBeTrue();
});

it('blocks a plain member from creating a workspace when creation_admins_only is on', function (): void {
    config()->set('relaticle.workspaces.creation_admins_only', true);

    $owner = User::factory()->withWorkspace()->create();
    $member = User::factory()->create();
    $owner->currentWorkspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);

    expect($member->fresh()->can('create', Workspace::class))->toBeFalse();
});

it('still applies the ownership cap to administrators', function (): void {
    config()->set('relaticle.workspaces.creation_admins_only', true);
    config()->set('relaticle.workspaces.max_owned_per_user', 1);

    $user = User::factory()->withWorkspace()->create();

    expect($user->can('create', Workspace::class))->toBeFalse();
});

it('explains the restriction instead of returning a bare 404', function (): void {
    config()->set('relaticle.workspaces.creation_admins_only', true);

    $owner = User::factory()->withWorkspace()->create();
    $member = User::factory()->create();
    $owner->currentWorkspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);

    $this->actingAs($member->fresh());

    $this->get(route('filament.app.tenant.registration'))
        ->assertRedirect()
        ->assertSessionHas('filament.notifications');
});
