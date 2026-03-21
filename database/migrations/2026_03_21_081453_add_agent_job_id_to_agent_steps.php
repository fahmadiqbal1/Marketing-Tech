<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_steps', function (Blueprint $table) {
            // Drop existing FK so we can make task_id nullable
            $table->dropForeign(['task_id']);

            // Allow task_id to be null (modern agents don't use AgentTask)
            $table->unsignedBigInteger('task_id')->nullable()->change();

            // Link to AgentJob (UUID) for modern agent system
            $table->uuid('agent_job_id')->nullable()->after('task_id')->index();

            // Restore FK with nullOnDelete instead of cascadeOnDelete
            $table->foreign('task_id')->references('id')->on('agent_tasks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('agent_steps', function (Blueprint $table) {
            $table->dropForeign(['task_id']);
            $table->dropIndex(['agent_job_id']);
            $table->dropColumn('agent_job_id');
            $table->unsignedBigInteger('task_id')->nullable(false)->change();
            $table->foreign('task_id')->references('id')->on('agent_tasks')->cascadeOnDelete();
        });
    }
};
