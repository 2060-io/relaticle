<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Features\OnboardSeed;
use App\Filament\Pages\Dashboard;
use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Relaticle\Chat\Services\ModelAccess;
use Relaticle\Chat\Services\ModelRegistry;

mutates(ModelRegistry::class, ModelAccess::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);

    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentWorkspace);
});

it('shows the Ollama model in the chat picker when configured', function (): void {
    config()->set('chat.ollama.model', 'qwen3:14b');
    app()->forgetInstance(ModelRegistry::class);

    Livewire::test(ChatInterface::class)
        ->assertSee('qwen3:14b', stripInitialData: false);
});

it('hides the Ollama model from the chat picker when not configured', function (): void {
    config()->set('chat.ollama.model', null);
    app()->forgetInstance(ModelRegistry::class);

    Livewire::test(ChatInterface::class)
        ->assertDontSee('qwen3:14b', stripInitialData: false);
});

it('hides cloud models whose provider key is not configured', function (): void {
    config()->set('ai.providers.openai.key', null);
    app()->forgetInstance(ModelRegistry::class);

    Livewire::test(ChatInterface::class)
        ->assertSee('Sonnet 5', stripInitialData: false)
        ->assertDontSee('GPT 5.5', stripInitialData: false);
});

it('shows the Ollama model on the dashboard picker when configured', function (): void {
    config()->set('chat.ollama.model', 'qwen3:14b');
    app()->forgetInstance(ModelRegistry::class);

    livewire(Dashboard::class)
        ->assertSee('qwen3:14b', stripInitialData: false);
});

it('hides the Ollama model from the dashboard picker when not configured', function (): void {
    config()->set('chat.ollama.model', null);
    app()->forgetInstance(ModelRegistry::class);

    livewire(Dashboard::class)
        ->assertDontSee('qwen3:14b', stripInitialData: false);
});

it('drives the chat picker from the model registry', function (): void {
    config()->set('ai.providers.openai.key', null);
    app()->forgetInstance(ModelRegistry::class);

    Livewire::test(ChatInterface::class)
        ->assertSee('Sonnet 5', stripInitialData: false)   // anthropic key set in tests
        ->assertSee('Auto', stripInitialData: false)
        ->assertDontSee('GPT 5.5', stripInitialData: false)   // openai key nulled → hidden
        ->assertDontSee('Gemini 3 Flash', stripInitialData: false); // supports_tools=false → never shown
});

it('shows env-configured self-hosted models in the picker', function (): void {
    config()->set('chat.self_hosted.url', 'http://vllm.local/v1');
    config()->set('chat.self_hosted.models', 'llama3.1:70b, qwen3:32b');
    config()->set('ai.providers.selfhosted.url', 'http://vllm.local/v1');
    app()->forgetInstance(ModelRegistry::class);

    Livewire::test(ChatInterface::class)
        ->assertSee('llama3.1:70b', stripInitialData: false)
        ->assertSee('qwen3:32b', stripInitialData: false);
});

it('tells a trial workspace without its own data how to unlock premium models', function (): void {
    $this->user->currentWorkspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(14)])->save();

    Livewire::test(ChatInterface::class)
        ->assertSee('Add your own records to unlock premium models during your trial.', stripInitialData: false)
        ->assertSee('Locked', stripInitialData: false)
        ->assertDontSee('Available on the Pro plan.', stripInitialData: false);
});

it('keeps the upgrade hint for a workspace on the free plan', function (): void {
    Livewire::test(ChatInterface::class)
        ->assertSee('Available on the Pro plan.', stripInitialData: false)
        ->assertDontSee('Add your own records to unlock premium models during your trial.', stripInitialData: false);
});

it('shows the trial hint on the dashboard composer too', function (): void {
    $this->user->currentWorkspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(14)])->save();

    livewire(Dashboard::class)
        ->assertSee('Add your own records to unlock premium models during your trial.', stripInitialData: false);
});
