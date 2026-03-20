<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workflow_id')->nullable();
            $table->uuid('workflow_task_id')->nullable();
            $table->string('agent_class', 200);
            $table->string('agent_type', 100);
            $table->string('status', 50)->default('pending');
            $table->text('instruction');
            $table->jsonb('messages')->default('[]');       // full conversation history
            $table->jsonb('tool_calls')->default('[]');     // all tool calls made
            $table->text('result')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('steps_taken')->default(0);
            $table->integer('max_steps')->default(20);
            $table->string('last_tool', 100)->nullable();
            $table->string('model_used', 100)->nullable();
            $table->string('provider', 50)->nullable();
            $table->integer('tokens_in')->default(0);
            $table->integer('tokens_out')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->bigInteger('chat_id')->nullable();
            $table->bigInteger('user_id')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('workflow_id')->references('id')->on('workflows')->onDelete('set null');
            $table->index('status');
            $table->index('agent_type');
            $table->index('chat_id');
            $table->index(['status', 'created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('agent_runs'); }
};
