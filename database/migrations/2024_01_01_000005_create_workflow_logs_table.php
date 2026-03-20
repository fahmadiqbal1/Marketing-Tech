<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('workflow_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('workflow_id');
            $table->uuid('workflow_task_id')->nullable();
            $table->uuid('agent_run_id')->nullable();
            $table->string('level', 20)->default('info'); // debug|info|warning|error|critical
            $table->string('event', 100);
            $table->text('message');
            $table->jsonb('context')->default('{}');
            $table->string('source', 100)->nullable(); // which service/agent logged this
            $table->timestamp('logged_at')->useCurrent();

            $table->foreign('workflow_id')->references('id')->on('workflows')->onDelete('cascade');
            $table->index(['workflow_id', 'logged_at']);
            $table->index('level');
            $table->index('event');
        });
    }
    public function down(): void { Schema::dropIfExists('workflow_logs'); }
};
