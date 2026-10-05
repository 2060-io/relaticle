<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['companies', 'people', 'opportunities', 'tasks', 'notes'] as $table) {
            DB::table($table)
                ->select('id')
                ->where('creation_source', 'system')
                ->chunkById(1000, function (iterable $records) use ($table): void {
                    DB::table($table)
                        ->whereIn('id', collect($records)->pluck('id'))
                        ->update(['creation_source' => 'sample']);
                });
        }

        DB::table('activity_log')
            ->select('id')
            ->whereRaw("properties::jsonb ->> 'source' = 'system'")
            ->chunkById(1000, function (iterable $activities): void {
                DB::table('activity_log')
                    ->whereIn('id', collect($activities)->pluck('id'))
                    ->update(['properties' => DB::raw("jsonb_set(properties::jsonb, '{source}', '\"sample\"')::json")]);
            });
    }
};
