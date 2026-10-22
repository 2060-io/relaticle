<?php

declare(strict_types=1);

use App\Actions\Task\NotifyTaskAssignees;
use App\Livewire\App\AppDatabaseNotifications;
use App\Models\Task;
use App\Models\User;

mutates(AppDatabaseNotifications::class);

it('keeps a notification in the open bell instead of dismissing it after a few seconds', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $teammate = User::factory()->create(['name' => 'Dana Reyes']);
    $workspace->users()->attach($teammate, ['role' => 'member']);
    $task = Task::factory()->for($workspace)->create(['title' => 'Send the renewal quote']);

    resolve(NotifyTaskAssignees::class)->execute($task, [$user->getKey()], $teammate);

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->assertScript('!! (window.Alpine && window.Livewire)', true)
        ->wait(1)
        ->click('.fi-sidebar-notifications-btn')
        ->assertSee('Send the renewal quote')
        ->wait(8)
        ->assertSee('Send the renewal quote')
        ->assertNoJavaScriptErrors();

    expect($user->notifications()->count())->toBe(1);
});
