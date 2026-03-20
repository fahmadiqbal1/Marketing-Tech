<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('agent_run_id')->nullable();
            $table->uuid('workflow_id')->nullable();
            $table->string('provider', 50);       // openai|anthropic
            $table->string('model', 100);
            $table->string('request_type', 50);   // chat|completion|embedding|transcription|vision
            $table->integer('tokens_in')->default(0);
            $table->integer('tokens_out')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->integer('duration_ms')->nullable();
            $table->string('status', 50)->default('success'); // success|failed|rate_limited|timeout
            $table->integer('http_status')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('retry_number')->default(0);
            $table->boolean('used_fallback')->default(false);
            $table->string('fallback_model', 100)->nullable();
            $table->jsonb('request_metadata')->default('{}'); // temperature, max_tokens etc
            $table->timestamp('requested_at')->useCurrent();

            $table->index('provider');
            $table->index('model');
            $table->index('agent_run_id');
            $table->index('requested_at');
            $table->index(['provider', 'requested_at']); // for cost aggregation
        });
    }
    public function down(): void { Schema::dropIfExists('ai_requests'); }
};
