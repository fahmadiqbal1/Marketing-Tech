<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Index: agent_steps(action, tool_success) — for tool reliability queries
        DB::statement('CREATE INDEX IF NOT EXISTS idx_agent_steps_action_success ON agent_steps(action, tool_success)');

        // Index: generated_outputs(content_variation_id) — if column exists
        if (Schema::hasColumn('generated_outputs', 'content_variation_id')) {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_generated_outputs_variation ON generated_outputs(content_variation_id)');
        }

        // Index: content_variations(agent_job_id)
        if (Schema::hasTable('content_variations')) {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_content_variations_job ON content_variations(agent_job_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_content_variations_created_job ON content_variations(created_at, agent_job_id)');
        }

        // Index: content_performance(content_variation_id)
        if (Schema::hasTable('content_performance')) {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_content_performance_variation ON content_performance(content_variation_id)');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_agent_steps_action_success');
        DB::statement('DROP INDEX IF EXISTS idx_generated_outputs_variation');
        DB::statement('DROP INDEX IF EXISTS idx_content_variations_job');
        DB::statement('DROP INDEX IF EXISTS idx_content_variations_created_job');
        DB::statement('DROP INDEX IF EXISTS idx_content_performance_variation');
    }
};
