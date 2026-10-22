<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const int EXPIRES_AFTER_DAYS = 30;

    public function up(): void
    {
        Schema::table('email_access_requests', function (Blueprint $table): void {
            $table->foreignUlid('workspace_id')->nullable()->constrained('workspaces')->cascadeOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignUlid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();

            $table->index(['owner_id', 'workspace_id', 'status']);
        });

        $expiresAt = Date::now()->addDays(self::EXPIRES_AFTER_DAYS);

        DB::table('email_access_requests')->eachById(function (object $request) use ($expiresAt): void {
            $workspaceId = $request->email_id === null
                ? null
                : DB::table('emails')->where('id', $request->email_id)->value('workspace_id');

            $update = ['workspace_id' => $workspaceId];

            if ($request->status === 'pending') {
                $update['expires_at'] = $expiresAt;
            }

            if ($request->status === 'pending' && $workspaceId === null) {
                $update['status'] = 'expired';
            }

            DB::table('email_access_requests')->where('id', $request->id)->update($update);
        });
    }
};
