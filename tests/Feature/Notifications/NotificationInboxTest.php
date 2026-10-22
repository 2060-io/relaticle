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
use Filament\Actions\Action;
use Filament\Notifications\DatabaseNotification as FilamentDatabaseNotification;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Tests\Helpers\GroupedInboxNotification;

mutates(AppDatabaseNotifications::class);

function assignTaskNotification(User $assignee, Workspace $workspace, string $title): void
{
    $actor = User::factory()->create();
    $workspace->users()->attach($actor, ['role' => 'member']);

    $task = Task::factory()->for($workspace)->create(['title' => $title]);
    $task->assignees()->attach($assignee);

    resolve(NotifyTaskAssignees::class)->execute($task, [$assignee->id], $actor);
}

/** @param  list<Action>  $actions */
function insertFilamentNotification(User $user, ?string $workspaceId, string $title, array $actions = []): string
{
    $id = (string) Str::uuid();

    DB::table('notifications')->insert([
        'id' => $id,
        'type' => FilamentDatabaseNotification::class,
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->getKey(),
        'data' => json_encode(FilamentNotification::make()->title($title)->actions($actions)->getDatabaseMessage()),
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

    expect($this->user->notifications()->where('workspace_id', $this->workspaceB->id)->exists())->toBeTrue();

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

it('keeps a notification in place when it is marked read', function (): void {
    $this->travelTo(now()->subHour());
    assignTaskNotification($this->user, $this->workspaceA, 'Older task');
    $this->travelTo(now()->addMinute());
    assignTaskNotification($this->user, $this->workspaceA, 'Newer task');
    $this->travelBack();

    $olderId = $this->user->notifications()->where('data->task_title', 'Older task')->value('id');

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->call('markNotificationAsRead', $olderId)
        ->assertSeeInOrder(['Newer task', 'Older task']);

    expect(Notification::query()->findOrFail($olderId)->read_at)->not->toBeNull();
});

it('moves a collapsed notification to the top', function (): void {
    $task = Task::factory()->for($this->workspaceA)->create();

    $this->travelTo(now()->subHour());
    $this->user->notifyNow(new GroupedInboxNotification($task, 'task:1', 'first'));
    $this->travelTo(now()->addMinute());
    assignTaskNotification($this->user, $this->workspaceA, 'Newer task');
    $this->travelTo(now()->addMinute());
    $this->user->notifyNow(new GroupedInboxNotification($task, 'task:1', 'second'));
    $this->travelBack();

    $groupedId = $this->user->notifications()->where('group_key', 'task:1')->value('id');

    $listedIds = Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->instance()
        ->getNotifications()
        ->pluck('id')
        ->all();

    expect($listedIds[0])->toBe($groupedId)
        ->and($listedIds)->toHaveCount(2);
});

it('renders a notification stored before this change', function (): void {
    insertFilamentNotification($this->user, $this->workspaceA->id, 'Legacy title', [
        Action::make('open')->label('Open legacy')->url('/legacy')->markAsRead(),
    ]);

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->assertSee('Legacy title')
        ->assertSee('Open legacy');
});

it('marks a notification stored before this change as read from its action', function (): void {
    $id = insertFilamentNotification($this->user, $this->workspaceA->id, 'Legacy title', [
        Action::make('open')->label('Open legacy')->url('/legacy')->markAsRead(),
    ]);

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->dispatch('markedNotificationAsRead', id: $id);

    expect(Notification::query()->findOrFail($id)->read_at)->not->toBeNull();
});

it('does not hand related records to the browser', function (): void {
    assignTaskNotification($this->user, $this->workspaceA, 'Task in A');

    $component = Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)->call('getNotifications');
    $returned = json_encode($component->effects['returns']);

    expect($returned)->toContain('Task in A')
        ->not->toContain('pm_last_four')
        ->not->toContain('ai_preferences')
        ->not->toContain('notification_preferences');
});

it('does not hand the workspace billing details to the browser', function (): void {
    $this->workspaceB->forceFill(['stripe_id' => 'cus_secret123', 'pm_type' => 'visa', 'pm_last_four' => '4242'])->save();
    $this->user->switchWorkspace($this->workspaceB);

    $component = Livewire::actingAs($this->user->refresh()->load('currentWorkspace'))
        ->test(AppDatabaseNotifications::class)
        ->call('getUser');
    $returned = json_encode($component->effects['returns']);

    expect($returned)->toContain($this->workspaceB->name)
        ->not->toContain('cus_secret123')
        ->not->toContain('4242')
        ->not->toContain('visa');
});

it('listens for live updates while the list is empty', function (): void {
    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)->assertSeeHtml('database-notifications.sent');
});

it('polls every 120 seconds as a fallback to live updates', function (): void {
    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)->assertSeeHtml('wire:poll.120s');
});
