<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tracks real-world outcome metrics per execution domain
        Schema::create('agent_outcomes', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(\Illuminate\Support\Facades\DB::raw('gen_random_uuid()'));
            $table->string('agent_job_id')->nullable()->index();
            $table->string('domain', 50)->index();          // campaign, hiring, content, growth, media
            $table->string('entity_id')->nullable()->index(); // campaign_id, job_posting_id, etc.
            $table->string('metric', 100);                  // engagement_rate, conversion, hire_success, etc.
            $table->float('value');                          // 0.0–1.0 normalised score
            $table->jsonb('metadata')->default('{}');
            $table->timestamp('created_at')->useCurrent();
        });

        // Captures user feedback: approvals, edits, rejections
        Schema::create('agent_feedback', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(\Illuminate\Support\Facades\DB::raw('gen_random_uuid()'));
            $table->string('agent_job_id')->nullable()->index();
            $table->string('user_action', 50);              // approved, edited, rejected, regenerated
            $table->jsonb('diff')->nullable();              // before/after for edits
            $table->text('inferred_reason')->nullable();    // LLM-inferred why user changed it
            $table->timestamp('created_at')->useCurrent();
        });

        // Every StrategicAgent evaluation + its eventual outcome score
        Schema::create('strategic_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(\Illuminate\Support\Facades\DB::raw('gen_random_uuid()'));
            $table->jsonb('input');                         // raw user instruction + context summary
            $table->jsonb('decision');                      // full StrategicAgent JSON output
            $table->string('action', 30)->index();          // APPROVE|MODIFY|REJECT|DELAY|REQUEST_INFO
            $table->float('confidence')->default(0.5);
            $table->float('outcome_score')->nullable();     // scored after execution completes
            $table->string('strategic_mode', 20)->default('shadow'); // shadow|advisory|active
            $table->timestamps();
        });

        // UCB1 bandit stats per model × task_type combination
        Schema::create('model_performance', function (Blueprint $table) {
            $table->id();
            $table->string('model_name', 100);
            $table->string('task_type', 100);
            $table->unsignedInteger('pulls')->default(0);
            $table->float('total_reward')->default(0);
            $table->float('success_rate')->default(0.5);
            $table->float('avg_latency_ms')->default(0);
            $table->float('avg_cost_usd')->default(0);
            $table->float('score')->default(0.5);
            $table->timestamp('last_updated_at')->useCurrent();
            $table->unique(['model_name', 'task_type']);
        });

        // Per-domain daily spend caps with ROI-based auto-rebalancing
        Schema::create('budget_allocations', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 50)->unique();
            $table->float('daily_budget')->default(1.0);    // USD
            $table->float('used_today')->default(0);
            $table->float('roi_score')->default(0.5);       // 0–1
            $table->date('reset_date')->nullable();
            $table->timestamps();
        });

        // Cross-agent shared working memory per task
        Schema::create('agent_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(\Illuminate\Support\Facades\DB::raw('gen_random_uuid()'));
            $table->string('task_id')->index();
            $table->jsonb('shared_context')->default('{}');
            $table->string('state', 20)->default('active'); // active|completed|failed
            $table->timestamps();
        });

        // Extracted higher-order insights from outcome patterns
        Schema::create('strategic_insights', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(\Illuminate\Support\Facades\DB::raw('gen_random_uuid()'));
            $table->string('domain', 50)->index();
            $table->string('metric', 100);
            $table->text('insight');                        // human-readable pattern
            $table->float('confidence')->default(0.5);
            $table->unsignedInteger('sample_size')->default(0);
            $table->jsonb('supporting_data')->default('{}');
            $table->timestamp('extracted_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategic_insights');
        Schema::dropIfExists('agent_sessions');
        Schema::dropIfExists('budget_allocations');
        Schema::dropIfExists('model_performance');
        Schema::dropIfExists('strategic_decisions');
        Schema::dropIfExists('agent_feedback');
        Schema::dropIfExists('agent_outcomes');
    }
};
