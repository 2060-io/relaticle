<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->foreignUlid('workspace_id')->nullable()->index()->constrained()->cascadeOnDelete();
            $table->foreignUlid('actor_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->nullableUlidMorphs('subject');
            $table->string('reason')->nullable();
            $table->string('group_key')->nullable();
            $table->timestamp('archived_at')->nullable();

            $table->index(['notifiable_id', 'workspace_id', 'archived_at', 'updated_at'], 'notifications_list_idx');
        });

        DB::statement('create index notifications_unread_idx on notifications (notifiable_id, workspace_id) where read_at is null and archived_at is null');
        DB::statement('create unique index notifications_group_key_unique on notifications (notifiable_id, group_key) where group_key is not null and read_at is null and archived_at is null');
    }
};
