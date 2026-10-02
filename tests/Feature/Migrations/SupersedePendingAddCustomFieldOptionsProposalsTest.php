<?php

declare(strict_types=1);

use App\Actions\CustomFields\UpdateCustomField;
use App\Models\CustomField;
use App\Models\User;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;

function runSupersedeAddCustomFieldOptionsMigration(): void
{
    $migration = require database_path('migrations/2026_10_02_120000_supersede_pending_add_custom_field_options_proposals.php');
    $migration->up();
}

it('supersedes pending proposals of the retired add options action and leaves the rest alone', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    $retired = PendingAction::query()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $owner->getKey(),
        'action_class' => 'App\Actions\CustomFields\AddCustomFieldOptions',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'custom_field',
        'action_data' => ['_record_id' => '01K6A0000000000000000000AA', 'options' => [['name' => 'Won']]],
        'display_data' => ['title' => 'Add Custom Field Options'],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    $decided = PendingAction::query()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $owner->getKey(),
        'action_class' => 'App\Actions\CustomFields\AddCustomFieldOptions',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'custom_field',
        'action_data' => ['_record_id' => '01K6A0000000000000000000AA', 'options' => [['name' => 'Lost']]],
        'display_data' => ['title' => 'Add Custom Field Options'],
        'status' => PendingActionStatus::Approved,
        'expires_at' => now()->addMinutes(15),
        'resolved_at' => now(),
    ]);

    $unrelated = PendingAction::query()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $owner->getKey(),
        'action_class' => UpdateCustomField::class,
        'operation' => PendingActionOperation::Update,
        'entity_type' => 'custom_field',
        'action_data' => ['_record_id' => '01K6A0000000000000000000AA', '_model_class' => CustomField::class, 'name' => 'Stage'],
        'display_data' => ['title' => 'Update Custom Field'],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    runSupersedeAddCustomFieldOptionsMigration();

    expect($retired->refresh()->status)->toBe(PendingActionStatus::Superseded)
        ->and($retired->resolved_at)->not->toBeNull()
        ->and($decided->refresh()->status)->toBe(PendingActionStatus::Approved)
        ->and($unrelated->refresh()->status)->toBe(PendingActionStatus::Pending);
});
