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

        DB::table('knowledge_base')
            ->select(['id', 'content'])
            ->orderBy('id')
            ->chunk(200, function ($rows): void {
                foreach ($rows as $row) {
                    $normalized = strtolower(preg_replace('/\s+/', ' ', trim($row->content)));
                    $hash = md5(mb_substr($normalized, 0, 1000, 'UTF-8'));

                    DB::table('knowledge_base')
                        ->where('id', $row->id)
                        ->update(['content_hash' => $hash]);
                }
            });
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
