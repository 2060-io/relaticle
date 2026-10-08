<?php

declare(strict_types=1);

use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Models\Company;
use App\Models\Export;
use App\Models\User;
use App\Policies\ExportPolicy;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;

mutates(ExportPolicy::class);

beforeEach(function (): void {
    Storage::fake('local');

    $this->workspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $this->user = User::factory()->create(['current_workspace_id' => $this->workspace->getKey()]);
    $this->workspace->users()->attach($this->user, ['role' => 'admin']);
    $this->actingAs($this->user);
    Filament::setTenant($this->workspace);

    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp']);

    livewire(ListCompanies::class)->callAction('export')->assertHasNoFormErrors();

    $this->export = Export::query()->latest()->firstOrFail();
    $this->downloadUrl = route('filament.exports.download', ['export' => $this->export, 'format' => 'csv']);
});

it('lets the owner of a workspace export download it', function (): void {
    expect($this->export->workspace_id)->toBe($this->workspace->getKey());

    $this->actingAs($this->user)->get($this->downloadUrl)->assertOk();
});

it('stops the owner downloading an export once removed from its workspace', function (): void {
    $this->workspace->removeUser($this->user);

    $this->actingAs($this->user->fresh())
        ->get($this->downloadUrl)
        ->assertForbidden();
});

it('stops another member downloading an export they do not own', function (): void {
    $teammate = User::factory()->create();
    $this->workspace->users()->attach($teammate, ['role' => 'admin']);

    $this->actingAs($teammate)
        ->get($this->downloadUrl)
        ->assertForbidden();
});

it('keeps owner-only access for a legacy export without a workspace', function (): void {
    $this->export->forceFill(['workspace_id' => null])->save();
    $this->workspace->removeUser($this->user);

    $this->actingAs($this->user->fresh())
        ->get($this->downloadUrl)
        ->assertOk();
});
