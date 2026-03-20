<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('agent_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workflow_id')->nullable();
            $table->string('agent_type', 100);
            $table->string('agent_class', 255);
            $table->text('instruction');
            $table->string('short_description', 200)->nullable();
            $table->string('status', 50)->default('pending');
            $table->text('result')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('steps_taken')->default(0);
            $table->string('last_tool', 100)->nullable();
            $table->bigInteger('chat_id')->nullable();
            $table->bigInteger('user_id')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('agent_type');
            $table->index('chat_id');
        });
    }
    public function down(): void { Schema::dropIfExists('agent_jobs'); }
};
