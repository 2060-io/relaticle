<?php

declare(strict_types=1);

use App\Enums\ActivationStep;
use App\Features\Chat;
use App\Features\SetupConversation;
use App\Filament\Pages\ChatConversation;
use App\Filament\Pages\Dashboard;
use App\Models\User;
use App\Onboarding\ActivationSteps;
use Filament\Facades\Filament;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\CachedState;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Feature;
use Relaticle\Chat\ChatServiceProvider;
use Spatie\Onboard\OnboardingStep;
use Spatie\Onboard\OnboardingSteps;

mutates(Chat::class, SetupConversation::class, ChatServiceProvider::class, Dashboard::class, ChatConversation::class, ActivationSteps::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
});

/** @return list<string> */
function activationStepKeys(object $workspace): array
{
    return resolve(OnboardingSteps::class)
        ->steps($workspace)
        ->map(fn (OnboardingStep $step): string => $step->attribute('key')->value)
        ->values()
        ->all();
}

it('is on by default', function (): void {
    expect(Feature::active(Chat::class))->toBeTrue();
});

it('renders every assistant entry point while the feature is on', function (): void {
    $html = $this->get(Dashboard::getUrl(tenant: $this->workspace))->assertOk()->getContent();

    expect($html)
        ->toContain('RECORD_CHIP_ICONS')
        ->toContain('app.chat.chat-sidebar-nav')
        ->toContain('app.chat.chat-side-panel')
        ->toContain('chat:toggle-panel')
        ->toContain('data-chat-context="dashboard"')
        ->toContain('dashboardChatInput');
});

it('removes every assistant entry point when the feature is off', function (): void {
    Feature::define(Chat::class, false);

    $html = $this->get(Dashboard::getUrl(tenant: $this->workspace))->assertOk()->getContent();

    expect($html)
        ->not->toContain('RECORD_CHIP_ICONS')
        ->not->toContain('app.chat.chat-sidebar-nav')
        ->not->toContain('app.chat.chat-side-panel')
        ->not->toContain('app.chat.chat-all-chats-panel')
        ->not->toContain('chat:toggle-panel')
        ->not->toContain('data-chat-context="dashboard"')
        ->not->toContain('dashboardChatInput')
        ->not->toContain(__('Ask :name', ['name' => config('chat.assistant_name')]))
        // The rest of the home page stays: navigation and the task list.
        ->toContain('fi-sidebar-nav')
        ->toContain(e(__('filament/pages/dashboard.tasks.heading')));
});

// Pennant memoises a resolved value per scope for the rest of the test, so each
// on/off pair below is two tests rather than one test that flips the flag.

it('serves the conversation page while the feature is on', function (): void {
    $this->get(ChatConversation::getUrl(tenant: $this->workspace))->assertOk();
});

it('denies the conversation page when the feature is off', function (): void {
    Feature::define(Chat::class, false);

    $this->get(ChatConversation::getUrl(tenant: $this->workspace))->assertForbidden();
});

it('lists the ask-the-assistant onboarding step while the feature is on', function (): void {
    expect(activationStepKeys($this->workspace))->toContain(ActivationStep::AskRela->value);
});

it('drops the ask-the-assistant onboarding step when the feature is off', function (): void {
    Feature::define(Chat::class, false);

    expect(activationStepKeys($this->workspace))
        ->not->toContain(ActivationStep::AskRela->value)
        ->toContain(ActivationStep::FirstRecord->value);
});

it('turns the setup conversation off with the chat', function (): void {
    config()->set('relaticle.features.setup_conversation', true);
    config()->set('relaticle.features.chat', false);
    Feature::flushCache();

    expect(Feature::active(Chat::class))->toBeFalse()
        ->and(Feature::active(SetupConversation::class))->toBeFalse();
});

it('registers the chat endpoints while the feature is on', function (): void {
    expect(Route::has('chat.send'))->toBeTrue()
        ->and(Route::has('chat.conversations'))->toBeTrue()
        ->and(Route::has('chat.record-redirect'))->toBeTrue();
});

it('drops the chat endpoints but keeps the record permalinks when the feature is off', function (): void {
    putenv('RELATICLE_FEATURE_CHAT=false');
    CachedState::$cachedRoutes = null;
    CachedState::$cachedConfig = null;
    RouteServiceProvider::loadCachedRoutesUsing(null);
    LoadConfiguration::alwaysUse(null);
    $this->refreshApplication();

    expect(Route::has('chat.send'))->toBeFalse()
        ->and(Route::has('chat.conversations'))->toBeFalse()
        ->and(Route::has('chat.transcribe'))->toBeFalse()
        ->and(Route::has('chat.record-redirect'))->toBeTrue();

    putenv('RELATICLE_FEATURE_CHAT');
    CachedState::$cachedRoutes = null;
    CachedState::$cachedConfig = null;
});
