<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_ai_platforms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('website_url')->nullable();
            $table->string('api_base_url');
            $table->string('default_model');
            $table->string('api_key_env');
            $table->enum('auth_type', ['bearer', 'x-api-key'])->default('bearer');
            $table->string('auth_header')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_ai_platforms');
    }
};
