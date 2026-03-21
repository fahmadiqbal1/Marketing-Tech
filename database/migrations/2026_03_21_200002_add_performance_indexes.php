<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add indexes to support:
 *  - 60-day lookback filter on content_variations (created_at)
 *  - Tool reliability aggregation on agent_steps (action, tool_success)
 *  - Content performance joins (content_variation_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_variations', function (Blueprint $table) {
            $table->index('created_at', 'cv_created_at_idx');
        });

        Schema::table('agent_steps', function (Blueprint $table) {
            $table->index('action', 'as_action_idx');
            $table->index(['action', 'tool_success'], 'as_action_success_idx');
        });

        Schema::table('content_performance', function (Blueprint $table) {
            // Guard — only add if not already indexed
            if (! collect(\DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'content_performance'"))
                    ->pluck('indexname')
                    ->contains('cp_variation_id_idx')) {
                $table->index('content_variation_id', 'cp_variation_id_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('content_variations', function (Blueprint $table) {
            $table->dropIndex('cv_created_at_idx');
        });

        Schema::table('agent_steps', function (Blueprint $table) {
            $table->dropIndex('as_action_idx');
            $table->dropIndex('as_action_success_idx');
        });

        Schema::table('content_performance', function (Blueprint $table) {
            $table->dropIndex('cp_variation_id_idx');
        });
    }
};
