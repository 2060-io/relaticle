<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\EmailIntegration\Livewire\EmailComposer;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

mutates(EmailComposer::class);

it('turns any typed email address into a recipient chip and keeps invalid text editable', function (string $theme): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'email_address' => 'olivia@acme.example',
        'sync_cursor' => 'history-done',
        'last_synced_at' => now(),
    ]));

    $page = visit('/app/login')->{$theme}()
        ->type('[id="form.email"]', $user->email)
        ->click('button[type="submit"]')
        ->type('[id="form.password"]', 'password')
        ->click('button[type="submit"]')
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/email")
        ->click(__('filament/concerns/email-compose.actions.compose.label'))
        ->waitForText(__('filament/emails/composer.title'))
        ->type('[role="combobox"]', 'new.lead@globex.example')
        ->keys('[role="combobox"]', ['Enter'])
        ->assertSee('new.lead@globex.example')
        ->type('[role="combobox"]', 'not-an-address')
        ->keys('[role="combobox"]', ['Enter'])
        ->assertValue('[role="combobox"]', 'not-an-address')
        ->assertNoJavaScriptErrors();

    $page->screenshot(filename: "composer-recipients-{$theme}");
})->with(['inLightMode', 'inDarkMode']);
