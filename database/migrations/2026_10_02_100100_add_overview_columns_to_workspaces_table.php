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
        DB::statement("SET LOCAL lock_timeout = '5s'");

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->timestamp('sales_contacted_at')->nullable();
            $table->string('setup_exit_reason', 32)->nullable();
            $table->string('setup_exit_note', 500)->nullable();
            $table->timestamp('setup_exit_reason_at')->nullable();
        });
    }
};
