<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('agent_steps', function (Blueprint $table) {
            $table->jsonb('knowledge_chunks_used')->nullable()->after('result');
            $table->boolean('from_cache')->default(false)->after('knowledge_chunks_used');
        });
    }

    public function down(): void
    {
        Schema::table('agent_steps', function (Blueprint $table) {
            $table->dropColumn(['knowledge_chunks_used', 'from_cache']);
        });
    }
};
