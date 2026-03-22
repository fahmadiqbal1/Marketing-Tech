<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        Schema::create('artifacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workflow_id')->nullable();
            $table->uuid('agent_run_id')->nullable();
            $table->uuid('parent_artifact_id')->nullable();
            $table->string('type', 100);           // text|image|video|pdf|json|campaign|email|job_post|candidate_score
            $table->string('name', 255);
            $table->text('content')->nullable();   // text artifacts
            $table->string('storage_key', 500)->nullable(); // for file artifacts in MinIO
            $table->string('storage_bucket', 100)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->bigInteger('file_size_bytes')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->boolean('approved')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('approved_by', 100)->nullable();
            $table->integer('version')->default(1);
            $table->boolean('is_final')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workflow_id')->references('id')->on('workflows')->onDelete('set null');
            $table->index('workflow_id');
            $table->index('type');
            $table->index('approved');
            $table->index('created_at');
        });

        if (DB::select("SELECT 1 FROM pg_available_extensions WHERE name='vector' AND installed_version IS NOT NULL")) {
            DB::statement('ALTER TABLE artifacts ADD COLUMN embedding vector(2000)');
        }
    }
    public function down(): void { Schema::dropIfExists('artifacts'); }
};
