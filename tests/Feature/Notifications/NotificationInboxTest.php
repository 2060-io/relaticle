<?php

declare(strict_types=1);

use App\Actions\Task\NotifyTaskAssignees;
use App\Features\OnboardSeed;
use App\Livewire\App\AppDatabaseNotifications;
use App\Models\Notification;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Filament\Notifications\DatabaseNotification as FilamentDatabaseNotification;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

mutates(AppDatabaseNotifications::class);

function assignTaskNotification(User $assignee, Workspace $workspace, string $title): void
{
    $actor = User::factory()->create();
    $workspace->users()->attach($actor, ['role' => 'member']);

    $task = Task::factory()->for($workspace)->create(['title' => $title]);
    $task->assignees()->attach($assignee);

    resolve(NotifyTaskAssignees::class)->execute($task, [$assignee->id], $actor);
}

function insertFilamentNotification(User $user, ?string $workspaceId, string $title): string
{
    $id = (string) Str::uuid();

    DB::table('notifications')->insert([
        'id' => $id,
        'type' => FilamentDatabaseNotification::class,
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->getKey(),
        'data' => json_encode(FilamentNotification::make()->title($title)->getDatabaseMessage()),
        'workspace_id' => $workspaceId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);

    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspaceA = $this->user->currentWorkspace;
    $this->workspaceB = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $this->workspaceB->users()->attach($this->user, ['role' => 'member']);

    $this->user->switchWorkspace($this->workspaceA);
    resolve(CurrentWorkspace::class)->set($this->workspaceA);
});

function seedBellInbox(User $user, Workspace $current, Workspace $other): void
{
    assignTaskNotification($user, $current, 'Task in A');
    assignTaskNotification($user, $other, 'Task in B');
    insertFilamentNotification($user, null, 'Account level title');
}

it('lists only the current workspace and account-level notifications', function (): void {
    seedBellInbox($this->user, $this->workspaceA, $this->workspaceB);

    $component = Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class);

    expect($component->instance()->getUnreadNotificationsCount())->toBe(2);

    $component
        ->assertSee('Task in A')
        ->assertSee('Account level title')
        ->assertDontSee('Task in B');
});

it('marks all as read only in the current workspace', function (): void {
    seedBellInbox($this->user, $this->workspaceA, $this->workspaceB);

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)->call('markAllNotificationsAsRead');

    $unreadWorkspaceIds = $this->user->notifications()->whereNull('read_at')->pluck('workspace_id')->all();

    expect($unreadWorkspaceIds)->toBe([$this->workspaceB->id]);
});

it('clears only the current workspace', function (): void {
    seedBellInbox($this->user, $this->workspaceA, $this->workspaceB);

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)->call('clearNotifications');

    expect($this->user->notifications()->pluck('workspace_id')->all())->toBe([$this->workspaceB->id]);
});

it('hides archived notifications', function (): void {
    seedBellInbox($this->user, $this->workspaceA, $this->workspaceB);
    $this->user->notifications()->where('workspace_id', $this->workspaceA->id)->update(['archived_at' => now()]);

    $component = Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class);

    expect($component->instance()->getUnreadNotificationsCount())->toBe(1);

    $component->assertDontSee('Task in A');
});

it('lists the most recently updated notification first', function (): void {
    assignTaskNotification($this->user, $this->workspaceA, 'Older task');
    assignTaskNotification($this->user, $this->workspaceA, 'Newer task');
    $this->user->notifications()->where('data->task_title', 'Older task')->update(['updated_at' => now()->addMinute()]);

    $titles = Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->instance()
        ->getNotifications()
        ->map(fn (Notification $row): string => $row->data['task_title'])
        ->all();

    expect($titles)->toBe(['Older task', 'Newer task']);
});

it('renders a notification stored before this change', function (): void {
    insertFilamentNotification($this->user, $this->workspaceA->id, 'Legacy title');

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)->assertSee('Legacy title');
});

it('listens for live updates while the list is empty', function (): void {
    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)->assertSeeHtml('database-notifications.sent');
});

it('polls every 120 seconds as a fallback to live updates', function (): void {
    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)->assertSeeHtml('wire:poll.120s');
});
