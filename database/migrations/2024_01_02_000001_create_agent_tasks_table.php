<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_tasks', function (Blueprint $table) {
            $table->id();
            $table->text('user_input');
            $table->string('status', 30)->default('pending');
            // pending | running | paused | completed | failed
            $table->unsignedSmallInteger('current_step')->default(0);
            $table->jsonb('final_output')->nullable();
            $table->string('ai_provider', 20)->default('openai');
            $table->string('model', 60)->nullable();
            $table->unsignedInteger('total_tokens')->default(0);
            $table->unsignedInteger('total_latency_ms')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tasks');
    }
};
