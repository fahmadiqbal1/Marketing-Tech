<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add content_hash to knowledge_base for centralised deduplication.
 *
 * The hash is computed as md5(mb_substr(lower(normalized_content), 0, 1000))
 * and stored at insert time. VectorStoreService checks this before embedding,
 * preventing duplicate entries across all ingestion sources (manual, GitHub,
 * agent skills seeder, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_base', function (Blueprint $table) {
            if (! Schema::hasColumn('knowledge_base', 'content_hash')) {
                $table->string('content_hash', 32)->nullable()->after('parent_id');
                $table->index('content_hash', 'kb_content_hash_idx');
            }
        });

        // Backfill existing rows so old entries are also deduplicated going forward
        DB::statement("
            UPDATE knowledge_base
            SET content_hash = md5(
                regexp_replace(lower(substr(content, 1, 1000)), '\s+', ' ', 'g')
            )
            WHERE content_hash IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('knowledge_base', function (Blueprint $table) {
            if (Schema::hasColumn('knowledge_base', 'content_hash')) {
                $table->dropIndex('kb_content_hash_idx');
                $table->dropColumn('content_hash');
            }
        });
    }
};
