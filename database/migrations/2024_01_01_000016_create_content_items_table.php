<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->longText('body');
            $table->string('type', 64)->index();
            // blog_post, social_twitter, social_linkedin, social_instagram,
            // email_newsletter, ad_copy, product_description, video_script, press_release
            $table->string('platform', 64)->nullable()->index();
            $table->string('status', 32)->default('draft')->index();
            // draft, ready, scheduled, published, archived
            $table->jsonb('tags')->default('[]');
            $table->integer('word_count')->default(0);
            $table->integer('char_count')->default(0);
            $table->jsonb('seo_analysis')->nullable();
            $table->jsonb('performance_metrics')->default('{}');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->uuid('agent_job_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'status']);
        });
        if (DB::connection()->getDriverName() === 'pgsql'
            && DB::select("SELECT 1 FROM pg_available_extensions WHERE name='vector' AND installed_version IS NOT NULL")) {
            DB::statement('ALTER TABLE content_items ADD COLUMN embedding vector(2000)');
        } else {
            Schema::table('content_items', function (Blueprint $table) {
                $table->longText('embedding')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('content_items');
    }
};
