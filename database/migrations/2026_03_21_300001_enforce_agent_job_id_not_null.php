<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Count and delete orphaned rows (NULL agent_job_id = legacy AgentTask system)
        $orphanCount = DB::table('agent_steps')->whereNull('agent_job_id')->count();
        if ($orphanCount > 0) {
            Log::warning('enforce_agent_job_id_not_null: deleting orphaned agent_steps rows (legacy task system)', [
                'count' => $orphanCount,
            ]);
            DB::table('agent_steps')->whereNull('agent_job_id')->delete();
        }

        // Step 2: Drop old non-unique index if it exists (we'll replace with FK)
        try {
            if (Schema::hasIndex('agent_steps', 'agent_steps_agent_job_id_index')) {
                Schema::table('agent_steps', function (Blueprint $table) {
                    $table->dropIndex('agent_steps_agent_job_id_index');
                });
            }
        } catch (\Throwable $e) {
            Log::warning('enforce_agent_job_id_not_null: could not drop old index (may not exist)', [
                'error' => $e->getMessage(),
            ]);
        }

        // Step 3: Enforce NOT NULL
        Schema::table('agent_steps', function (Blueprint $table) {
            $table->uuid('agent_job_id')->nullable(false)->change();
        });

        // Step 4: Add FK with cascade delete
        Schema::table('agent_steps', function (Blueprint $table) {
            $table->foreign('agent_job_id', 'fk_agent_steps_agent_job_id')
                ->references('id')
                ->on('agent_jobs')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('agent_steps', function (Blueprint $table) {
            $table->dropForeign('fk_agent_steps_agent_job_id');
            $table->uuid('agent_job_id')->nullable()->change();
            $table->index('agent_job_id');
        });
    }
};
