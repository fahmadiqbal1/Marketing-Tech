<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('agent_tasks')->cascadeOnDelete();
            $table->unsignedSmallInteger('step_number');
            $table->string('agent_name', 60)->default('MasterAgent');
            $table->text('thought')->nullable();
            $table->string('action', 100)->nullable();
            $table->jsonb('parameters')->nullable();
            $table->jsonb('result')->nullable();
            $table->string('status', 30)->default('running');
            // running | completed | failed | skipped
            $table->unsignedInteger('tokens_used')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->timestamps();

            $table->index(['task_id', 'step_number']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_steps');
    }
};
