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

        Schema::create('ai_provider_costs', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->date('date');
            $table->bigInteger('amount_micros');
            $table->timestamp('fetched_at');
            $table->unique(['provider', 'date']);
        });
    }
};
