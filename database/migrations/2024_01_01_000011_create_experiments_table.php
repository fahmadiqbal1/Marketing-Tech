<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('experiments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255);
            $table->string('type', 100);          // ab_test|multivariate|bandit
            $table->string('category', 100);       // campaign|content|hiring|growth
            $table->string('status', 50)->default('draft'); // draft|running|paused|concluded|archived
            $table->text('hypothesis');
            $table->string('metric_primary', 100); // open_rate|ctr|conversion|revenue
            $table->jsonb('metrics_secondary')->default('[]');
            $table->jsonb('variants')->default('[]'); // [{name,description,config,traffic_pct}]
            $table->string('winning_variant', 100)->nullable();
            $table->decimal('confidence_level', 5, 2)->default(95.00);
            $table->decimal('achieved_significance', 5, 4)->nullable(); // p-value
            $table->integer('min_sample_size')->default(100);
            $table->integer('current_sample_size')->default(0);
            $table->jsonb('results')->default('{}');
            $table->text('conclusion')->nullable();
            $table->jsonb('learnings')->default('[]');   // stored for context graph
            $table->boolean('auto_generated')->default(false);
            $table->uuid('parent_campaign_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('concluded_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('category');
            $table->index('type');
        });

        Schema::create('experiment_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('experiment_id');
            $table->string('variant', 100);
            $table->string('event_type', 100);    // impression|click|conversion|unsubscribe
            $table->decimal('value', 12, 4)->default(0);
            $table->jsonb('metadata')->default('{}');
            $table->timestamp('occurred_at')->useCurrent();

            $table->foreign('experiment_id')->references('id')->on('experiments')->onDelete('cascade');
            $table->index(['experiment_id', 'variant', 'event_type']);
            $table->index('occurred_at');
        });
    }
    public function down(): void {
        Schema::dropIfExists('experiment_events');
        Schema::dropIfExists('experiments');
    }
};
