<?php

declare(strict_types=1);

use App\Enums\SetupExitReason;
use App\Models\User;
use Illuminate\Support\Facades\URL;

function feedbackUrl(string $workspaceId, SetupExitReason $reason): string
{
    return URL::signedRoute('onboarding.feedback', ['workspace' => $workspaceId, 'reason' => $reason->value]);
}

it('shows the chosen reason without storing anything on open', function (): void {
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;

    $this->get(feedbackUrl($workspace->getKey(), SetupExitReason::TooHard))
        ->assertOk()
        ->assertSee(SetupExitReason::TooHard->getLabel());

    expect($workspace->refresh()->setup_exit_reason)->toBeNull();
});

it('stores the reason and the note when sent, and a second answer overwrites the first', function (): void {
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;

    $this->post(feedbackUrl($workspace->getKey(), SetupExitReason::TooHard), ['note' => 'Import was confusing'])
        ->assertOk()
        ->assertSee(__('mail.setup_feedback.done_heading'));

    $workspace->refresh();
    expect($workspace->setup_exit_reason)->toBe(SetupExitReason::TooHard)
        ->and($workspace->setup_exit_note)->toBe('Import was confusing')
        ->and($workspace->setup_exit_reason_at)->not->toBeNull();

    $this->post(feedbackUrl($workspace->getKey(), SetupExitReason::ChoseAnother))->assertOk();

    expect($workspace->refresh()->setup_exit_reason)->toBe(SetupExitReason::ChoseAnother)
        ->and($workspace->setup_exit_note)->toBeNull();
});

it('rejects a tampered link', function (): void {
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $url = feedbackUrl($workspace->getKey(), SetupExitReason::TooHard);

    $this->get(str_replace('too_hard', 'just_looking', $url))->assertForbidden();
});

it('rejects a note over 500 characters', function (): void {
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;

    $this->post(feedbackUrl($workspace->getKey(), SetupExitReason::TooHard), ['note' => str_repeat('a', 501)])
        ->assertSessionHasErrors('note');

    expect($workspace->refresh()->setup_exit_reason)->toBeNull();
});
