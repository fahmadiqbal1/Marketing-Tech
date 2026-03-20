<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        Schema::create('context_graph_nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 100);         // fact|campaign|candidate|experiment|result|learning|entity
            $table->string('category', 100)->nullable(); // marketing|hiring|growth|product|company
            $table->string('title', 500);
            $table->text('content');
            $table->jsonb('attributes')->default('{}');
            $table->jsonb('tags')->default('[]');
            $table->integer('importance')->default(5); // 1-10
            $table->integer('access_count')->default(0);
            $table->decimal('relevance_decay', 4, 3)->default(1.0); // reduces with age
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('category');
            $table->index(['type', 'category']);
        });

        if (DB::select("SELECT 1 FROM pg_available_extensions WHERE name='vector' AND installed_version IS NOT NULL")) {
            DB::statement('ALTER TABLE context_graph_nodes ADD COLUMN embedding vector(3072)');
            DB::statement('CREATE INDEX context_graph_nodes_content_fts ON context_graph_nodes USING gin(to_tsvector(\'english\', content))');
        }

        Schema::create('context_graph_edges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('source_node_id');
            $table->uuid('target_node_id');
            $table->string('relation_type', 100); // caused|improved|related_to|followed_by|contradicts|supports
            $table->decimal('strength', 4, 3)->default(1.0); // 0-1 edge weight
            $table->text('description')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->foreign('source_node_id')->references('id')->on('context_graph_nodes')->onDelete('cascade');
            $table->foreign('target_node_id')->references('id')->on('context_graph_nodes')->onDelete('cascade');
            $table->unique(['source_node_id', 'target_node_id', 'relation_type']);
            $table->index(['source_node_id', 'relation_type']);
            $table->index('target_node_id');
        });
    }
    public function down(): void {
        Schema::dropIfExists('context_graph_edges');
        Schema::dropIfExists('context_graph_nodes');
    }
};
