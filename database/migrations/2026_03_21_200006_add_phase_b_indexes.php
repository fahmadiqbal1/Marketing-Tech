<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_steps', function (Blueprint $table) {
            $table->index(['action', 'tool_success'], 'idx_agent_steps_action_success');
        });

        // Index: generated_outputs(content_variation_id) — if column exists
        if (Schema::hasColumn('generated_outputs', 'content_variation_id')) {
            Schema::table('generated_outputs', function (Blueprint $table) {
                $table->index('content_variation_id', 'idx_generated_outputs_variation');
            });
        }

        // Index: content_variations(agent_job_id)
        if (Schema::hasTable('content_variations')) {
            Schema::table('content_variations', function (Blueprint $table) {
                $table->index('agent_job_id', 'idx_content_variations_job');
                $table->index(['created_at', 'agent_job_id'], 'idx_content_variations_created_job');
            });
        }

        // Index: content_performance(content_variation_id)
        if (Schema::hasTable('content_performance')) {
            Schema::table('content_performance', function (Blueprint $table) {
                $table->index('content_variation_id', 'idx_content_performance_variation');
            });
        }
    }

    public function down(): void
    {
        Schema::table('agent_steps', function (Blueprint $table) {
            $table->dropIndex('idx_agent_steps_action_success');
        });

        if (Schema::hasIndex('generated_outputs', 'idx_generated_outputs_variation')) {
            Schema::table('generated_outputs', function (Blueprint $table) {
                $table->dropIndex('idx_generated_outputs_variation');
            });
        }

        if (Schema::hasIndex('content_variations', 'idx_content_variations_job')) {
            Schema::table('content_variations', function (Blueprint $table) {
                $table->dropIndex('idx_content_variations_job');
                $table->dropIndex('idx_content_variations_created_job');
            });
        }

        if (Schema::hasIndex('content_performance', 'idx_content_performance_variation')) {
            Schema::table('content_performance', function (Blueprint $table) {
                $table->dropIndex('idx_content_performance_variation');
            });
        }
    }
};
