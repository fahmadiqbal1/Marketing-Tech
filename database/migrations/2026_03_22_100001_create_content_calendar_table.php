<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_calendar', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->enum('platform', ['tiktok', 'instagram', 'facebook', 'twitter', 'linkedin']);
            $table->enum('content_type', ['reel', 'story', 'post', 'thread', 'carousel', 'live', 'ad']);
            $table->text('draft_content')->nullable();
            $table->enum('status', ['draft', 'scheduled', 'pending_approval', 'published', 'failed'])->default('draft');
            $table->enum('moderation_status', ['pending', 'approved', 'rejected', 'auto_approved'])->default('auto_approved');
            $table->text('moderation_notes')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignUuid('agent_job_id')->nullable()->constrained('agent_jobs')->nullOnDelete();
            $table->uuid('content_variation_id')->nullable();
            $table->uuid('campaign_id')->nullable();
            $table->string('external_post_id')->nullable();
            $table->jsonb('hashtags')->default('[]');
            $table->jsonb('metadata')->default('{}');
            $table->smallInteger('retry_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['platform', 'status']);
            $table->index('scheduled_at');
            $table->index('agent_job_id');
            $table->index('campaign_id');
            $table->index('content_variation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_calendar');
    }
};
