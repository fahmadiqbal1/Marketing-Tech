<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds nullable business_id to all tenant-scoped tables.
 * No FK constraint — avoids orphan errors on existing rows (NULL = unowned/shared).
 * Queue workers are unauthed → BusinessScope is a no-op → agents see all rows.
 */
return new class extends Migration
{
    private array $tables = [
        'social_accounts',
        'content_calendar',
        'campaigns',
        'knowledge_base',
        'hashtag_sets',
        'agent_jobs',
        'candidates',
        'job_postings',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'business_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->uuid('business_id')->nullable()->index()->after('id');
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'business_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('business_id');
                });
            }
        }
    }
};
