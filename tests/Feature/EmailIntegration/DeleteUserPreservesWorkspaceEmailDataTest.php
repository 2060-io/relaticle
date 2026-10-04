<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Jetstream\Contracts\DeletesUsers;
use Relaticle\EmailIntegration\EmailIntegrationServiceProvider;
use Relaticle\EmailIntegration\Models\EmailTemplate;
use Relaticle\EmailIntegration\Models\ProtectedRecipient;
use Relaticle\EmailIntegration\Models\WorkspaceEmailBlocklist;

mutates(EmailIntegrationServiceProvider::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;

    $this->leaver = User::factory()->create();
    $this->workspace->users()->attach($this->leaver, ['role' => 'member']);
});

it('keeps a workspace blocklist entry and nulls its creator when the creator is deleted', function (): void {
    $entry = WorkspaceEmailBlocklist::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->leaver->id,
    ]);

    resolve(DeletesUsers::class)->delete($this->leaver);

    expect(WorkspaceEmailBlocklist::query()->whereKey($entry->getKey())->exists())->toBeTrue()
        ->and(WorkspaceEmailBlocklist::query()->whereKey($entry->getKey())->value('created_by'))->toBeNull();
});

it('keeps a protected recipient and nulls its creator when the creator is deleted', function (): void {
    $recipient = ProtectedRecipient::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->leaver->id,
    ]);

    resolve(DeletesUsers::class)->delete($this->leaver);

    expect(ProtectedRecipient::query()->whereKey($recipient->getKey())->exists())->toBeTrue()
        ->and(ProtectedRecipient::query()->whereKey($recipient->getKey())->value('created_by'))->toBeNull();
});

it('keeps a shared template and nulls its creator when the creator is deleted', function (): void {
    $template = EmailTemplate::factory()->shared()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->leaver->id,
    ]);

    resolve(DeletesUsers::class)->delete($this->leaver);

    expect(EmailTemplate::query()->whereKey($template->getKey())->exists())->toBeTrue()
        ->and(EmailTemplate::query()->whereKey($template->getKey())->value('created_by'))->toBeNull();
});

it('removes a personal template when its creator is deleted', function (): void {
    $template = EmailTemplate::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->leaver->id,
        'is_shared' => false,
    ]);

    resolve(DeletesUsers::class)->delete($this->leaver);

    expect(EmailTemplate::withTrashed()->whereKey($template->getKey())->exists())->toBeFalse();
});

it('removes the creator\'s trashed and other-workspace personal templates', function (): void {
    $otherWorkspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $otherWorkspace->users()->attach($this->leaver, ['role' => 'member']);

    $trashed = EmailTemplate::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->leaver->id,
        'is_shared' => false,
    ]);
    $trashed->delete();

    $elsewhere = EmailTemplate::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'created_by' => $this->leaver->id,
        'is_shared' => false,
    ]);

    resolve(DeletesUsers::class)->delete($this->leaver);

    expect(EmailTemplate::withTrashed()->whereKey([$trashed->getKey(), $elsewhere->getKey()])->exists())->toBeFalse();
});
