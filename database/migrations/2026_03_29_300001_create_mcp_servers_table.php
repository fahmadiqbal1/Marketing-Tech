<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_servers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('business_id')->nullable()->index();
            $table->string('name');
            $table->enum('transport', ['stdio', 'sse', 'http'])->default('sse');
            $table->string('command')->nullable();
            $table->string('url')->nullable();
            $table->jsonb('args')->default('[]');
            $table->jsonb('env_vars')->default('{}');
            $table->boolean('is_active')->default(true);
            $table->jsonb('capabilities')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_servers');
    }
};
