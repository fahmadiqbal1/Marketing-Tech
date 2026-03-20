<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        Schema::create('candidates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('applied_job_id')->nullable();
            $table->string('name', 255);
            $table->string('email', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('location', 255)->nullable();
            $table->string('linkedin_url', 500)->nullable();
            $table->string('github_url', 500)->nullable();
            $table->text('summary')->nullable();
            $table->integer('years_experience')->default(0);
            $table->string('current_title', 255)->nullable();
            $table->string('current_company', 255)->nullable();
            $table->jsonb('skills')->default('[]');
            $table->jsonb('education')->default('[]');
            $table->jsonb('experience')->default('[]');
            $table->jsonb('certifications')->default('[]');
            $table->jsonb('languages')->default('["English"]');
            $table->string('source', 100)->default('direct');
            $table->string('pipeline_stage', 50)->default('applied');
            $table->text('pipeline_notes')->nullable();
            $table->timestamp('stage_updated_at')->nullable();
            $table->decimal('score', 5, 2)->nullable();
            $table->jsonb('score_details')->nullable();
            $table->uuid('scored_for_job')->nullable();
            $table->text('cv_raw')->nullable();
            $table->jsonb('outreach_history')->default('[]');
            $table->timestamps();
            $table->softDeletes();

            $table->index('applied_job_id');
            $table->index('pipeline_stage');
            $table->index('score');
            $table->index('email');
        });

        if (DB::select("SELECT 1 FROM pg_available_extensions WHERE name='vector' AND installed_version IS NOT NULL")) {
            DB::statement('ALTER TABLE candidates ADD COLUMN embedding vector(3072)');
        }
    }
    public function down(): void { Schema::dropIfExists('candidates'); }
};
