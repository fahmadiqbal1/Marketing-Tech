<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('agent_steps', function (Blueprint $table) {
            $table->boolean('tool_success')->nullable()->after('from_cache');
            $table->text('tool_error')->nullable()->after('tool_success');
            // [{id: uuid, score: 0.87}, ...] — richer than knowledge_chunks_used (just IDs)
            $table->jsonb('knowledge_scores')->nullable()->after('tool_error');
        });
    }

    public function down(): void
    {
        Schema::table('agent_steps', function (Blueprint $table) {
            $table->dropColumn(['tool_success', 'tool_error', 'knowledge_scores']);
        });
    }
};
