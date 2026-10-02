<?php

declare(strict_types=1);

use App\Enums\CustomFields\PeopleField;
use App\Models\CustomField;
use App\Models\People;
use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Livewire\EmailComposer;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;

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

it('lists a CRM person once when they are also a recent correspondent', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'email_address' => 'olivia@acme.example',
        'sync_cursor' => 'history-done',
        'last_synced_at' => now(),
    ]));

    $person = People::factory()->for($workspace)->create(['name' => 'Dana Globex', 'creator_id' => $user->id]);
    $emailsField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $workspace->id)
        ->where('entity_type', 'people')
        ->where('code', PeopleField::EMAILS->value)
        ->firstOrFail();
    $person->saveCustomFieldValue($emailsField, ['dana@globex.example'], $workspace);

    $email = Email::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'connected_account_id' => $account->id,
        'status' => EmailStatus::SYNCED,
        'sent_at' => now()->subHour(),
    ]);
    EmailParticipant::factory()->create([
        'email_id' => $email->id,
        'email_address' => 'dana@globex.example',
        'role' => EmailParticipantRole::FROM,
    ]);

    visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->click('button[type="submit"]')
        ->type('[id="form.password"]', 'password')
        ->click('button[type="submit"]')
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/email")
        ->click(__('filament/concerns/email-compose.actions.compose.label'))
        ->waitForText(__('filament/emails/composer.title'))
        ->type('[role="combobox"]', 'dana@globex')
        ->assertScript('Array.from(document.querySelectorAll("[role=option]")).filter((option) => option.offsetParent !== null && option.textContent.includes("dana@globex.example")).length', 1)
        ->assertNoJavaScriptErrors();
});
