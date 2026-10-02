<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

function runTurnOffPostgresJitMigration(): void
{
    $migration = require database_path('migrations/2026_10_02_152242_turn_off_postgres_jit_for_the_application_database.php');
    $migration->up();
}

function applicationDatabaseSettings(): string
{
    return (string) DB::scalar(
        "SELECT coalesce(array_to_string(setconfig, ','), '') FROM pg_db_role_setting WHERE setrole = 0 AND setdatabase = (SELECT oid FROM pg_database WHERE datname = current_database())"
    );
}

test('turns jit off for new sessions on the application database', function (): void {
    DB::statement('ALTER DATABASE '.DB::getQueryGrammar()->wrap(DB::getDatabaseName()).' RESET jit');

    runTurnOffPostgresJitMigration();

    expect(applicationDatabaseSettings())->toContain('jit=off');
});

test('can run again without failing', function (): void {
    runTurnOffPostgresJitMigration();
    runTurnOffPostgresJitMigration();

    expect(applicationDatabaseSettings())->toContain('jit=off');
});
