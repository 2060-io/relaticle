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

        Schema::table('ai_credit_transactions', function (Blueprint $table): void {
            $table->integer('cache_read_tokens')->default(0);
            $table->integer('cache_write_tokens')->default(0);
            $table->bigInteger('cost_micros')->nullable();
        });

        DB::statement('ALTER TABLE ai_credit_transactions ADD CONSTRAINT ai_credit_transactions_cache_tokens_nonneg CHECK (cache_read_tokens >= 0 AND cache_write_tokens >= 0)');
        DB::statement('ALTER TABLE ai_credit_transactions ADD CONSTRAINT ai_credit_transactions_cost_nonneg CHECK (cost_micros IS NULL OR cost_micros >= 0)');
    }
};
