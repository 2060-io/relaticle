<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $nullableByColumn = [
            'emails' => ['rfc_message_id' => true, 'provider_message_id' => true, 'thread_id' => true, 'in_reply_to' => true],
            'email_participants' => ['name' => true],
            'email_attachments' => ['filename' => false, 'mime_type' => false, 'content_id' => true],
            'email_threads' => ['thread_id' => false],
            'email_labels' => ['label' => false],
            'meetings' => [
                'provider_event_id' => false,
                'provider_recurring_event_id' => true,
                'ical_uid' => true,
                'title' => false,
                'location' => true,
                'organizer_email' => true,
                'organizer_name' => true,
                'html_link' => true,
            ],
            'meeting_attendees' => ['email_address' => false, 'name' => true],
            'connected_accounts' => ['display_name' => true],
        ];

        foreach ($nullableByColumn as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                foreach ($columns as $column => $nullable) {
                    $blueprint->text($column)->nullable($nullable)->change();
                }
            });
        }
    }
};
