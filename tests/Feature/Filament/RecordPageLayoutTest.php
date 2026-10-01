<?php

declare(strict_types=1);

use App\Filament\Concerns\CountsRelatedRecords;
use App\Filament\Concerns\HasRecordPageLayout;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\CompanyResource\RelationManagers\NotesRelationManager;
use App\Filament\Resources\CompanyResource\RelationManagers\PeopleRelationManager;
use App\Filament\Resources\CompanyResource\RelationManagers\TasksRelationManager;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use App\Filament\Resources\PeopleResource;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(HasRecordPageLayout::class, CountsRelatedRecords::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
    TenantContextService::setTenantId($this->workspace->getKey());
});

function railAction(string $name): TestAction
{
    return TestAction::make($name)->schemaComponent('recordActions', schema: 'infolist');
}

dataset('record pages', [
    'company' => [Company::class, ViewCompany::class, CompanyResource::class, 'Companies'],
    'person' => [People::class, ViewPeople::class, PeopleResource::class, 'People'],
    'opportunity' => [Opportunity::class, ViewOpportunity::class, OpportunityResource::class, 'Opportunities'],
]);

it('titles the :dataset page with the record name under a link back to its list', function (string $model, string $page, string $resource, string $listLabel): void {
    $record = $model::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Northwind Traders']);

    livewire($page, ['record' => $record->getKey()])
        ->assertSeeHtml('href="'.$resource::getUrl('index').'"')
        ->assertSeeInOrder([$listLabel, 'Northwind Traders'])
        ->assertDontSee('View Northwind Traders');
})->with('record pages');

it('offers edit, copy and delete from the details rail on the :dataset page', function (string $model, string $page): void {
    $record = $model::factory()->recycle([$this->user, $this->workspace])->create();

    livewire($page, ['record' => $record->getKey()])
        ->assertActionExists(railAction('edit'))
        ->assertActionExists(railAction('copyPageUrl'))
        ->assertActionExists(railAction('copyRecordId'))
        ->assertActionExists(railAction('delete'));
})->with('record pages');

it('deletes the record from the rail and returns to the list', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->callAction(railAction('delete'))
        ->assertRedirect(CompanyResource::getUrl('index'));

    expect($company->fresh()->trashed())->toBeTrue();
});

it('shows a custom field saved through the rail edit without a reload', function (): void {
    CustomField::factory()->create([
        'tenant_id' => $this->workspace->getKey(),
        'entity_type' => 'opportunity',
        'type' => 'text',
        'code' => 'deal_code',
        'name' => 'Deal code',
    ]);

    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create([
        'custom_fields' => ['deal_code' => 'OLD-001'],
    ]);

    livewire(ViewOpportunity::class, ['record' => $opportunity->getKey()])
        ->assertSee('OLD-001')
        ->callAction(railAction('edit'), data: ['custom_fields' => ['deal_code' => 'NEW-002']])
        ->assertHasNoActionErrors()
        ->assertSee('NEW-002')
        ->assertDontSee('OLD-001');
});

it('keeps details past the first eight behind a view all toggle', function (): void {
    foreach (range(1, 8) as $position) {
        CustomField::factory()->create([
            'tenant_id' => $this->workspace->getKey(),
            'entity_type' => 'company',
            'type' => 'text',
            'code' => "extra_{$position}",
            'name' => "Extra detail {$position}",
            'sort_order' => 100 + $position,
        ]);
    }

    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertSee('View all')
        ->assertSeeHtml('x-show="showAllDetails"')
        ->assertSee('Extra detail 8');
});

it('shows no view all toggle when every detail fits', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertDontSee('View all');
});

it('counts related records on the work pane tabs and omits empty counts', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    People::factory()->count(2)->recycle([$this->user, $this->workspace])->create(['company_id' => $company->getKey()]);

    expect(PeopleRelationManager::getBadge($company, ViewCompany::class))->toBe('2')
        ->and(TasksRelationManager::getBadge($company, ViewCompany::class))->toBeNull();
});

it('tells the record page to refresh its tab counts after a related record changes', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(NotesRelationManager::class, ['ownerRecord' => $company, 'pageClass' => ViewCompany::class])
        ->callAction(TestAction::make('create')->table(), data: ['title' => 'Kickoff notes'])
        ->assertHasNoActionErrors()
        ->assertDispatched('related-records-changed');

    expect(NotesRelationManager::getBadge($company, ViewCompany::class))->toBe('1');
});
