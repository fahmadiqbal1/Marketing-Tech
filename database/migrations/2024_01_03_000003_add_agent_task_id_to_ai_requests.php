<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            // Integer FK to agent_tasks (new system). No FK constraint to avoid cascade coupling.
            $table->unsignedBigInteger('agent_task_id')->nullable()->after('agent_run_id');
            $table->index('agent_task_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->dropIndex(['agent_task_id']);
            $table->dropColumn('agent_task_id');
        });
    }
};
