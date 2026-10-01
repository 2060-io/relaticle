<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['people', 'companies'] as $tableName) {
            if (Schema::hasIndex($tableName, ['id'], 'primary')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->primary('id');
            });
        }
    }
};
