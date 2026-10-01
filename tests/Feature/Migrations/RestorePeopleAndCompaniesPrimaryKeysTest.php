<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\People;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('upgrades people and companies without primary keys before creating email participants and meeting attendees', function (): void {
    $company = Company::factory()->create();
    $person = People::factory()->for($company)->create();

    DB::statement('DROP TABLE email_participants, meeting_attendees');
    DB::statement('ALTER TABLE people DROP CONSTRAINT people_pkey CASCADE');
    DB::statement('ALTER TABLE companies DROP CONSTRAINT companies_pkey CASCADE');

    DB::table('migrations')->whereIn('migration', [
        '2026_03_21_050441_restore_people_and_companies_primary_keys',
        '2026_03_21_050442_create_email_participants_table',
        '2026_04_20_000003_create_meeting_attendees_table',
        '2026_09_29_210525_widen_provider_sourced_email_and_calendar_columns',
        '2026_09_29_222721_index_email_participant_and_attendee_addresses_by_host',
    ])->delete();

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    expect(Schema::hasIndex('people', ['id'], 'primary'))->toBeTrue()
        ->and(Schema::hasIndex('companies', ['id'], 'primary'))->toBeTrue()
        ->and(collect(Schema::getForeignKeys('email_participants'))->pluck('foreign_table')->all())
        ->toContain('people', 'companies')
        ->and(collect(Schema::getForeignKeys('meeting_attendees'))->pluck('foreign_table')->all())
        ->toContain('people', 'companies');

    expect(fn (): bool => DB::table('people')->insert((array) DB::table('people')->where('id', $person->id)->first()))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('leaves people and companies untouched when their primary keys already exist', function (): void {
    $indexes = [Schema::getIndexes('people'), Schema::getIndexes('companies')];
    DB::table('migrations')->where('migration', '2026_03_21_050441_restore_people_and_companies_primary_keys')->delete();

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    expect([Schema::getIndexes('people'), Schema::getIndexes('companies')])->toBe($indexes);
});
