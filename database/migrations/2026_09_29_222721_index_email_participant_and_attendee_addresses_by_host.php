<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['email_participants', 'meeting_attendees'] as $table) {
            DB::statement("create index if not exists {$table}_lower_email_address_index on {$table} (lower(email_address))");
            DB::statement("create index if not exists {$table}_email_host_index on {$table} (split_part(lower(email_address), '@', 2))");
        }
    }
};
