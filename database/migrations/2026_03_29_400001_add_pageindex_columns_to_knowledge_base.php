<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds PageIndex vectorless RAG columns to knowledge_base.
 * - index_tree: hierarchical JSON table-of-contents built by LLM at ingest time
 * - node_id: short ID tying each chunk to its node in the parent's index_tree
 * Embedding column is kept (nullable) so existing data is not lost, but is no
 * longer required for retrieval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_base', function (Blueprint $table) {
            $table->jsonb('index_tree')->nullable()->after('content_hash');
            $table->string('node_id', 16)->nullable()->after('index_tree');
        });

        // Make embedding nullable so new entries don't require it
        if (config('database.default') === 'pgsql') {
            \Illuminate\Support\Facades\DB::statement(
                'ALTER TABLE knowledge_base ALTER COLUMN embedding DROP NOT NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::table('knowledge_base', function (Blueprint $table) {
            $table->dropColumn(['index_tree', 'node_id']);
        });
    }
};
