<?php

declare(strict_types=1);

use App\Actions\Onboarding\RecordSetupExitReason;
use App\Enums\SetupExitReason;
use App\Http\Controllers\Onboarding\SetupFeedbackController;
use App\Models\User;
use Illuminate\Support\Facades\URL;

mutates(SetupFeedbackController::class, RecordSetupExitReason::class);

function feedbackUrl(string $workspaceId, SetupExitReason $reason): string
{
    return URL::signedRoute('onboarding.feedback', ['workspace' => $workspaceId, 'reason' => $reason->value]);
}

it('shows the linked reason preselected without storing anything on open', function (): void {
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;

    $this->get(feedbackUrl($workspace->getKey(), SetupExitReason::TooHard))
        ->assertOk()
        ->assertSeeHtml('name="reason" value="too_hard" checked')
        ->assertDontSeeHtml('name="reason" value="just_looking" checked')
        ->assertSee(__('mail.setup_feedback.hint'));

    expect($workspace->refresh()->setup_exit_reason)->toBeNull();
});

it('stores the reason and the note when sent, and a second answer overwrites the first', function (): void {
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $url = feedbackUrl($workspace->getKey(), SetupExitReason::TooHard);

    $this->post($url, ['reason' => SetupExitReason::TooHard->value, 'note' => 'Import was confusing'])
        ->assertOk()
        ->assertSee(__('mail.setup_feedback.done_heading'));

    $workspace->refresh();
    expect($workspace->setup_exit_reason)->toBe(SetupExitReason::TooHard)
        ->and($workspace->setup_exit_note)->toBe('Import was confusing')
        ->and($workspace->setup_exit_reason_at)->not->toBeNull();

    $this->post($url, ['reason' => SetupExitReason::ChoseAnother->value])->assertOk();

    expect($workspace->refresh()->setup_exit_reason)->toBe(SetupExitReason::ChoseAnother)
        ->and($workspace->setup_exit_note)->toBeNull();
});

it('stores the reason picked on the page, not the one the link was signed for', function (): void {
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;

    $this->post(feedbackUrl($workspace->getKey(), SetupExitReason::TooHard), ['reason' => SetupExitReason::ChoseAnother->value])
        ->assertOk();

    expect($workspace->refresh()->setup_exit_reason)->toBe(SetupExitReason::ChoseAnother);
});

it('rejects a missing or unknown reason', function (): void {
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $url = feedbackUrl($workspace->getKey(), SetupExitReason::TooHard);

    $this->post($url, ['note' => 'No reason given'])->assertSessionHasErrors('reason');
    $this->post($url, ['reason' => 'bogus'])->assertSessionHasErrors('reason');

    expect($workspace->refresh()->setup_exit_reason)->toBeNull();
});

it('stores a note of 499 visible characters whose line breaks the browser submits as CRLF', function (): void {
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $browserNote = str_repeat('a', 165)."\r\n".str_repeat('b', 166)."\r\n".str_repeat('c', 166);

    $this->post(feedbackUrl($workspace->getKey(), SetupExitReason::TooHard), ['reason' => SetupExitReason::TooHard->value, 'note' => $browserNote])
        ->assertOk()
        ->assertSee(__('mail.setup_feedback.done_heading'));

    $workspace->refresh();
    expect($workspace->setup_exit_reason)->toBe(SetupExitReason::TooHard)
        ->and($workspace->setup_exit_note)->toBe(str_replace("\r\n", "\n", $browserNote))
        ->and((string) $workspace->setup_exit_note)->toHaveLength(499);
});

it('rejects a note over 500 characters and shows the error and the typed note on the page', function (): void {
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $note = str_repeat('a', 501);

    $this->followingRedirects()
        ->post(feedbackUrl($workspace->getKey(), SetupExitReason::TooHard), ['reason' => SetupExitReason::ChoseAnother->value, 'note' => $note])
        ->assertOk()
        ->assertSee(__('validation.max.string', ['attribute' => 'note', 'max' => 500]))
        ->assertSee($note)
        ->assertSeeHtml('name="reason" value="chose_another" checked');

    expect($workspace->refresh()->setup_exit_reason)->toBeNull();
});

it('shows the page again after a note sent as a list instead of text', function (): void {
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;

    $this->followingRedirects()
        ->post(feedbackUrl($workspace->getKey(), SetupExitReason::TooHard), ['reason' => SetupExitReason::TooHard->value, 'note' => ['x']])
        ->assertOk();

    expect($workspace->refresh()->setup_exit_reason)->toBeNull();
});

it('rejects a tampered link', function (): void {
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $url = feedbackUrl($workspace->getKey(), SetupExitReason::TooHard);

    $this->get(str_replace('too_hard', 'just_looking', $url))->assertForbidden();
});

it('rejects a link whose workspace was swapped for another one', function (): void {
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $other = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $url = feedbackUrl($workspace->getKey(), SetupExitReason::TooHard);

    $this->get(str_replace($workspace->getKey(), $other->getKey(), $url))->assertForbidden();
});

it('stores nothing from a post to a tampered link', function (): void {
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $other = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $url = feedbackUrl($workspace->getKey(), SetupExitReason::TooHard);

    $this->post(str_replace($workspace->getKey(), $other->getKey(), $url), ['reason' => SetupExitReason::TooHard->value])->assertForbidden();
    $this->post(str_replace('too_hard', 'just_looking', $url), ['reason' => SetupExitReason::TooHard->value])->assertForbidden();

    expect($workspace->refresh()->setup_exit_reason)->toBeNull()
        ->and($other->refresh()->setup_exit_reason)->toBeNull();
});
