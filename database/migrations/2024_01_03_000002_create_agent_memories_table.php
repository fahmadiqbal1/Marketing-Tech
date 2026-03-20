<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('agent_memories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_task_id');
            $table->string('memory_key', 100);
            $table->text('value');
            $table->string('context', 500)->nullable(); // brief label e.g. "competitor analysis result"
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('agent_task_id')
                  ->references('id')
                  ->on('agent_tasks')
                  ->onDelete('cascade');

            $table->index('agent_task_id');
            $table->index(['agent_task_id', 'memory_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_memories');
    }
};
