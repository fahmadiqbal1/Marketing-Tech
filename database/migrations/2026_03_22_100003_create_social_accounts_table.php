<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('platform', ['tiktok', 'instagram', 'facebook', 'twitter', 'linkedin']);
            $table->string('handle');
            $table->string('display_name')->nullable();
            $table->string('platform_user_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->boolean('is_connected')->default(false);
            $table->unsignedInteger('follower_count')->default(0);
            $table->decimal('avg_engagement_rate', 5, 4)->default(0);
            $table->jsonb('metadata')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'handle']);
            $table->index('platform');
            $table->index('is_connected');
            $table->index('platform_user_id');
            $table->index('token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
