<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Only create indexes if pgvector extension is available
        try {
            DB::statement("SELECT 'vector'::regtype");
        } catch (\Throwable) {
            // pgvector not installed — skip silently
            return;
        }

        // IVFFlat index on knowledge_base.embedding (up to 2000 dims — cosine similarity)
        // Note: IVFFlat requires > 0 rows
        DB::statement(
            "CREATE INDEX IF NOT EXISTS knowledge_base_embedding_ivfflat_idx
             ON knowledge_base
             USING ivfflat (embedding vector_cosine_ops)
             WITH (lists = 100)"
        );

        // HNSW index on workflows.embedding if column exists
        $hasWorkflowEmbedding = DB::select(
            "SELECT 1 FROM information_schema.columns
             WHERE table_name = 'workflows' AND column_name = 'embedding'
             LIMIT 1"
        );

        if (! empty($hasWorkflowEmbedding)) {
            DB::statement(
                "CREATE INDEX IF NOT EXISTS workflows_embedding_hnsw_idx
                 ON workflows
                 USING hnsw (embedding vector_cosine_ops)
                 WITH (m = 16, ef_construction = 64)"
            );
        }
    }

    public function down(): void
    {
        try {
            DB::statement("DROP INDEX IF EXISTS knowledge_base_embedding_hnsw_idx");
            DB::statement("DROP INDEX IF EXISTS workflows_embedding_hnsw_idx");
        } catch (\Throwable) {
            // Ignore — pgvector may not be installed
        }
    }
};
