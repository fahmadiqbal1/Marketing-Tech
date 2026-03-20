<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        Schema::create('knowledge_base', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title', 500);
            $table->longText('content');
            $table->string('category', 100)->default('general');
            $table->jsonb('tags')->default('[]');
            $table->string('source', 255)->nullable();
            $table->integer('chunk_index')->default(0);       // chunk sequence within parent
            $table->uuid('parent_id')->nullable();            // links chunks to first chunk
            $table->integer('access_count')->default(0);
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('category');
            $table->index('parent_id');
            $table->index(['category', 'created_at']);
        });

        if (DB::select("SELECT 1 FROM pg_available_extensions WHERE name='vector' AND installed_version IS NOT NULL")) {
            DB::statement('ALTER TABLE knowledge_base ADD COLUMN embedding vector(3072)');
            DB::statement("CREATE INDEX knowledge_base_fts_idx ON knowledge_base USING gin(to_tsvector('english', content))");
        }
    }

    public function down(): void {
        Schema::dropIfExists('knowledge_base');
    }
};
