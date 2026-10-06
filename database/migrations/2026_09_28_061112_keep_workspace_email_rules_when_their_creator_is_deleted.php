<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['protected_recipients', 'workspace_email_blocklists', 'email_templates'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropForeign(['created_by']);
                $blueprint->ulid('created_by')->nullable()->change();
                $blueprint->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            });
        }
    }
};
