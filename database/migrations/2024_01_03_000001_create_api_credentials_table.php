<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('api_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50);           // 'openai', 'gemini', 'anthropic', 'telegram'
            $table->string('env_key', 100);           // 'OPENAI_API_KEY', 'GEMINI_API_KEY', etc.
            $table->text('encrypted_value');          // Crypt::encryptString()
            $table->boolean('is_valid')->default(true);
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'env_key']);
            $table->index('env_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_credentials');
    }
};
