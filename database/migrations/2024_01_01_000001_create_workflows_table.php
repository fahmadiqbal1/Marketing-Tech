<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('type')->default('general'); // general, marketing, hiring, media, growth
            $table->string('status', 32)->default('pending');
            // pending, intake, context_retrieval, planning, task_execution,
            // review, owner_approval, execution, observation, learning, completed, failed, cancelled
            $table->text('instruction');
            $table->jsonb('context')->default('{}');
            $table->jsonb('plan')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->text('result')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);
            $table->integer('max_retries')->default(3);
            $table->bigInteger('chat_id')->nullable()->index();
            $table->bigInteger('user_id')->nullable()->index();
            $table->boolean('requires_approval')->default(false);
            $table->boolean('approval_granted')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
            $table->index(['type', 'status']);
        });

        // pgvector embedding column — ivfflat index skipped (requires ≤2000 dims; use HNSW or sequential scan for 3072)
        if (DB::select("SELECT 1 FROM pg_available_extensions WHERE name='vector' AND installed_version IS NOT NULL")) {
            DB::statement('ALTER TABLE workflows ADD COLUMN embedding vector(3072)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workflows');
    }
};
