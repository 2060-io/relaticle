<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('custom_fields')
            ->where('entity_type', 'company')
            ->where('code', 'domains')
            ->where('type', 'link')
            ->update(['settings' => DB::raw(<<<'SQL'
                jsonb_set(
                    case when jsonb_typeof(settings::jsonb) = 'object' then settings::jsonb else '{}'::jsonb end,
                    '{additional}',
                    case when jsonb_typeof(settings::jsonb -> 'additional') = 'object' then settings::jsonb -> 'additional' else '{}'::jsonb end
                        || '{"link_variant": "domain"}'::jsonb
                )::json
                SQL)]);
    }
};
