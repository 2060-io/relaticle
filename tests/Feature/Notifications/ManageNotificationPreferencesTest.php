<?php

declare(strict_types=1);

use App\Enums\Notifications\NotificationChannel;
use App\Enums\Notifications\NotificationType;
use App\Features\EmailIntegration;
use App\Filament\Pages\NotificationPreferences;
use App\Livewire\App\Notifications\ManageNotificationPreferences;
use App\Models\User;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

it('hydrates cells and the digest toggle from defaults', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    Livewire::test(ManageNotificationPreferences::class)
        ->assertSet('cells.task_assigned.in_app', true)
        ->assertSet('cells.task_assigned.email', false)
        ->assertSet('digestEnabled', true);
});

it('persists a matrix cell instantly when toggled', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    Livewire::test(ManageNotificationPreferences::class)
        ->set('cells.task_assigned.email', true);

    expect($user->fresh()->wantsNotification(NotificationType::TaskAssigned, NotificationChannel::Email))->toBeTrue();
});

it('persists the digest toggle instantly', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    Livewire::test(ManageNotificationPreferences::class)
        ->set('digestEnabled', false);

    expect($user->fresh()->wantsNotification(NotificationType::TaskDigest, NotificationChannel::Email))->toBeFalse();
});

it('renders the standalone notifications page', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $this->actingAs($user)
        ->get(NotificationPreferences::getUrl(tenant: $user->personalWorkspace()))
        ->assertOk()
        ->assertSee('Daily digest')
        ->assertSee('Task Assignments');
});

it('offers the email access and shared emails rows while email integration is active', function (): void {
    Feature::for(null)->activate(EmailIntegration::class);
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    Livewire::test(ManageNotificationPreferences::class)
        ->assertSee('Email access')
        ->assertSee('Shared emails')
        ->assertSet('cells.email_shared.in_app', true)
        ->assertSet('cells.email_shared.email', true)
        ->assertSet('cells.email_access.in_app', true)
        ->assertSet('cells.email_access.email', true);
});

it('hides the email access and shared emails rows while email integration is off', function (): void {
    Feature::for(null)->deactivate(EmailIntegration::class);
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    Livewire::test(ManageNotificationPreferences::class)
        ->assertDontSee('Email access')
        ->assertDontSee('Shared emails')
        ->assertSee('Task Assignments');
});

it('persists turning off shared emails in the app and leaves its email on', function (): void {
    Feature::for(null)->activate(EmailIntegration::class);
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    Livewire::test(ManageNotificationPreferences::class)
        ->set('cells.email_shared.in_app', false);

    expect($user->fresh()->wantsNotification(NotificationType::EmailShared, NotificationChannel::InApp))->toBeFalse()
        ->and($user->fresh()->wantsNotification(NotificationType::EmailShared, NotificationChannel::Email))->toBeTrue();
});
