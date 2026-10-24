<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\CustomFieldValue;
use App\Models\People;
use App\Models\User;
use App\Support\CustomFields\CanonicalValue;
use Relaticle\ImportWizard\Data\EntityLink;
use Relaticle\ImportWizard\Data\MatchableField;
use Relaticle\ImportWizard\Enums\EntityLinkSource;
use Relaticle\ImportWizard\Support\EntityLinkResolver;
use Tests\Helpers\LegacyCompanyDomains;
use Tests\Helpers\WorkspaceCustomField;

mutates(EntityLinkResolver::class, CanonicalValue::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
});

it('resolves workspace member by email via pivot', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => 'member']);

    $resolver = new EntityLinkResolver($this->workspace->id);
    $link = EntityLink::belongsTo('account_owner', User::class)
        ->matchableFields([MatchableField::email('email')])
        ->foreignKey('account_owner_id');
    $matcher = MatchableField::email('email');

    $result = $resolver->batchResolve($link, $matcher, [$member->email]);

    expect($result[$member->email])->toBe($member->id);
});

it('resolves workspace owner by email', function (): void {
    $resolver = new EntityLinkResolver($this->workspace->id);
    $link = EntityLink::belongsTo('account_owner', User::class)
        ->matchableFields([MatchableField::email('email')])
        ->foreignKey('account_owner_id');
    $matcher = MatchableField::email('email');

    $result = $resolver->batchResolve($link, $matcher, [$this->user->email]);

    expect($result[$this->user->email])->toBe($this->user->id);
});

it('resolves workspace member by ID', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => 'member']);

    $resolver = new EntityLinkResolver($this->workspace->id);
    $link = EntityLink::belongsTo('account_owner', User::class)
        ->matchableFields([MatchableField::id()])
        ->foreignKey('account_owner_id');
    $matcher = MatchableField::id();

    $result = $resolver->batchResolve($link, $matcher, [$member->id]);

    expect($result[$member->id])->toBe($member->id);
});

it('returns null for non-workspace-member email', function (): void {
    $stranger = User::factory()->create();

    $resolver = new EntityLinkResolver($this->workspace->id);
    $link = EntityLink::belongsTo('account_owner', User::class)
        ->matchableFields([MatchableField::email('email')])
        ->foreignKey('account_owner_id');
    $matcher = MatchableField::email('email');

    $result = $resolver->batchResolve($link, $matcher, [$stranger->email]);

    expect($result[$stranger->email])->toBeNull();
});

it('resolves multiple workspace members in batch', function (): void {
    $member1 = User::factory()->create();
    $member2 = User::factory()->create();
    $this->workspace->users()->attach($member1, ['role' => 'member']);
    $this->workspace->users()->attach($member2, ['role' => 'member']);

    $resolver = new EntityLinkResolver($this->workspace->id);
    $link = EntityLink::belongsTo('account_owner', User::class)
        ->matchableFields([MatchableField::email('email')])
        ->foreignKey('account_owner_id');
    $matcher = MatchableField::email('email');

    $result = $resolver->batchResolve($link, $matcher, [$member1->email, $member2->email]);

    expect($result[$member1->email])->toBe($member1->id)
        ->and($result[$member2->email])->toBe($member2->id);
});

it('links a row to the company storing the legacy www spelling of its domain', function (): void {
    $company = Company::factory()->for($this->workspace)->create();
    LegacyCompanyDomains::write($this->workspace, $company, ['www.acme.com']);

    $resolver = new EntityLinkResolver($this->workspace->id);

    $result = $resolver->batchResolve(EntityLink::company(), MatchableField::domain('custom_fields_domains'), ['https://www.acme.com']);

    expect($result['https://www.acme.com'])->toBe($company->id);
});

it('links a row to the company storing its domain in another letter case', function (): void {
    $company = Company::factory()->for($this->workspace)->create();
    LegacyCompanyDomains::write($this->workspace, $company, ['Acme.COM']);

    $resolver = new EntityLinkResolver($this->workspace->id);

    $result = $resolver->batchResolve(EntityLink::company(), MatchableField::domain('custom_fields_domains'), ['acme.com']);

    expect($result['acme.com'])->toBe($company->id);
});

it('matches a legacy-format value by the identical csv value and a canonical value by a formatted one', function (string $stored, string $csvValue): void {
    $field = WorkspaceCustomField::byCode($this->workspace->id, 'people', 'phone_number');
    $person = People::factory()->create(['workspace_id' => $this->workspace->id]);

    CustomFieldValue::factory()->withJsonValue([$stored])->create([
        'custom_field_id' => $field->id,
        'entity_type' => 'people',
        'entity_id' => $person->id,
        'tenant_id' => $this->workspace->id,
    ]);

    $link = new EntityLink(key: 'self', source: EntityLinkSource::Relationship, targetEntity: 'people', targetModelClass: People::class);
    $resolved = (new EntityLinkResolver((string) $this->workspace->id))
        ->batchResolve($link, MatchableField::phone(), [$csvValue]);

    expect((string) $resolved[$csvValue])->toBe((string) $person->id);
})->with([
    'legacy value, identical csv value' => ['+1 415-555-0100', '+1 415-555-0100'],
    'canonical value, formatted csv value' => ['+14155550100', '+1 (415) 555-0100'],
]);

it('prefers the record storing the exact csv value over one storing its canonical form', function (): void {
    $field = WorkspaceCustomField::byCode($this->workspace->id, 'people', 'phone_number');
    $legacy = People::factory()->create(['workspace_id' => $this->workspace->id]);
    $canonical = People::factory()->create(['workspace_id' => $this->workspace->id]);

    foreach ([[$legacy, '+1 415-555-0100'], [$canonical, '+14155550100']] as [$person, $stored]) {
        CustomFieldValue::factory()->withJsonValue([$stored])->create([
            'custom_field_id' => $field->id,
            'entity_type' => 'people',
            'entity_id' => $person->id,
            'tenant_id' => $this->workspace->id,
        ]);
    }

    $link = new EntityLink(key: 'self', source: EntityLinkSource::Relationship, targetEntity: 'people', targetModelClass: People::class);
    $resolved = (new EntityLinkResolver((string) $this->workspace->id))
        ->batchResolve($link, MatchableField::phone(), ['+1 415-555-0100']);

    expect((string) $resolved['+1 415-555-0100'])->toBe((string) $legacy->id);
});
