<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hashtag_sets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->enum('platform', ['tiktok', 'instagram', 'facebook', 'twitter', 'linkedin']);
            $table->string('niche')->nullable();
            $table->jsonb('tags')->default('[]');
            $table->enum('reach_tier', ['low', 'medium', 'high'])->default('medium');
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();

            $table->index('platform');
            $table->index(['platform', 'niche']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hashtag_sets');
    }
};
