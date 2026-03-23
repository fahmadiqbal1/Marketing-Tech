<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Add deleted_at indexes to all soft-delete tables for query performance.
     * KnowledgeBase already has its own index (2026_03_21_300003).
     */
    public function up(): void
    {
        $tables = [
            'artifacts',
            'campaigns',
            'candidates',
            'job_postings',
            'media_assets',
            'workflows',
            'content_calendar',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->index('deleted_at');
                });
            }
        }
    }

    public function down(): void
    {
        $tables = [
            'artifacts',
            'campaigns',
            'candidates',
            'job_postings',
            'media_assets',
            'workflows',
            'content_calendar',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $blueprint) use ($table) {
                    $blueprint->dropIndex(["{$table}_deleted_at_index"]);
                });
            }
        }
    }
};
