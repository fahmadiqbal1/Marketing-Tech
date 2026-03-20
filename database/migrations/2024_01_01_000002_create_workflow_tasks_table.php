<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('workflow_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workflow_id');
            $table->string('name', 200);
            $table->string('type', 100);           // agent_run|skill_exec|media_process|ai_request
            $table->string('status', 50)->default('pending'); // pending|running|completed|failed|skipped|cancelled
            $table->integer('sequence')->default(0);
            $table->string('agent_type', 100)->nullable();
            $table->string('skill_name', 100)->nullable();
            $table->jsonb('input')->default('{}');
            $table->jsonb('output')->default('{}');
            $table->jsonb('metadata')->default('{}');
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);
            $table->integer('max_retries')->default(3);
            $table->integer('timeout_seconds')->default(300);
            $table->string('depends_on_task_id', 36)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('workflow_id')->references('id')->on('workflows')->onDelete('cascade');
            $table->index(['workflow_id', 'sequence']);
            $table->index('status');
            $table->index('type');
        });
    }
    public function down(): void { Schema::dropIfExists('workflow_tasks'); }
};
