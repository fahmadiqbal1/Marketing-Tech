<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workflow_id')->nullable();
            $table->string('name', 255);
            $table->string('type', 50);       // email|social|meta_ads|google_ads|sms
            $table->string('status', 50)->default('draft'); // draft|scheduled|sending|sent|active|paused|completed|failed
            $table->string('subject', 500)->nullable();
            $table->longText('body')->nullable();
            $table->string('audience', 255)->default('all');
            $table->string('list_id', 255)->nullable();
            $table->jsonb('ab_variants')->default('[]');
            $table->string('winning_variant', 100)->nullable();
            $table->integer('send_count')->default(0);
            $table->integer('open_count')->default(0);
            $table->integer('click_count')->default(0);
            $table->integer('conversion_count')->default(0);
            $table->integer('unsubscribe_count')->default(0);
            $table->decimal('revenue_attributed', 12, 2)->default(0);
            $table->jsonb('performance_data')->default('{}');
            $table->uuid('experiment_id')->nullable();
            $table->boolean('created_by_agent')->default(false);
            $table->uuid('agent_run_id')->nullable();
            $table->timestamp('schedule_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('type');
            $table->index('schedule_at');
            $table->index('workflow_id');
        });
    }
    public function down(): void { Schema::dropIfExists('campaigns'); }
};
