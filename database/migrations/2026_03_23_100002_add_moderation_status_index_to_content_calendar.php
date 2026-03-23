<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add index on content_calendar.moderation_status.
 *
 * This column is queried on every DispatchScheduledPosts run (every minute)
 * via the scheduledNow() scope: whereIn('moderation_status', ['approved','auto_approved']).
 * Without an index this is a full table scan on every dispatch cycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_calendar', function (Blueprint $table) {
            $table->index('moderation_status', 'content_calendar_moderation_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('content_calendar', function (Blueprint $table) {
            $table->dropIndex('content_calendar_moderation_status_idx');
        });
    }
};
