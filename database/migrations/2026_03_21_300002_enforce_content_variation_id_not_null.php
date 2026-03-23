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
        // Step 1: Best-effort backfill — link orphaned outputs to the earliest variation
        // for the same agent_job_id. Outputs with no variation at all will be deleted below.
        DB::statement('
            UPDATE generated_outputs
            SET content_variation_id = (
                SELECT cv.id
                FROM content_variations cv
                WHERE cv.agent_job_id = generated_outputs.agent_job_id
                ORDER BY cv.created_at ASC
                LIMIT 1
            )
            WHERE content_variation_id IS NULL
              AND agent_job_id IS NOT NULL
        ');

        // Step 2: Delete any remaining nulls (no variation exists for that job, or no agent_job_id)
        $remaining = DB::table('generated_outputs')->whereNull('content_variation_id')->count();
        if ($remaining > 0) {
            Log::warning('enforce_content_variation_id_not_null: deleting unresolvable outputs (no linked variation)', [
                'count' => $remaining,
            ]);
            DB::table('generated_outputs')->whereNull('content_variation_id')->delete();
        }

        // Step 3: Drop old index if it exists (replaced by FK index)
        try {
            if (Schema::hasIndex('generated_outputs', 'idx_generated_outputs_variation')) {
                Schema::table('generated_outputs', function (Blueprint $table) {
                    $table->dropIndex('idx_generated_outputs_variation');
                });
            }
        } catch (Throwable $e) {
            Log::warning('enforce_content_variation_id_not_null: could not drop old index', [
                'error' => $e->getMessage(),
            ]);
        }

        // Step 4: Enforce NOT NULL
        Schema::table('generated_outputs', function (Blueprint $table) {
            $table->uuid('content_variation_id')->nullable(false)->change();
        });

        // Step 5: Add FK with cascade delete
        Schema::table('generated_outputs', function (Blueprint $table) {
            $table->foreign('content_variation_id', 'fk_generated_outputs_variation')
                ->references('id')
                ->on('content_variations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('generated_outputs', function (Blueprint $table) {
            $table->dropForeign('fk_generated_outputs_variation');
            $table->uuid('content_variation_id')->nullable()->change();
        });
    }
};
