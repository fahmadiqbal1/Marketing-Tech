<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('system_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('event_type', 100);    // workflow_started|agent_failed|skill_executed|media_processed etc
            $table->string('severity', 20)->default('info'); // debug|info|warning|error|critical
            $table->string('source', 100);         // which service emitted this
            $table->uuid('entity_id')->nullable(); // workflow/agent/media etc id
            $table->string('entity_type', 100)->nullable();
            $table->text('message');
            $table->jsonb('payload')->default('{}');
            $table->bigInteger('chat_id')->nullable(); // if notification should go to telegram
            $table->boolean('notified')->default(false);
            $table->timestamp('occurred_at')->useCurrent();

            $table->index('event_type');
            $table->index('severity');
            $table->index(['severity', 'notified']); // for supervisor to pick up
            $table->index('occurred_at');
        });
    }
    public function down(): void { Schema::dropIfExists('system_events'); }
};
