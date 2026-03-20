<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('job_postings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title', 255);
            $table->string('department', 100)->nullable();
            $table->string('location', 255)->default('Remote');
            $table->string('employment_type', 50)->default('full_time');
            $table->string('level', 50)->default('mid');
            $table->text('description');
            $table->jsonb('requirements')->default('[]');
            $table->jsonb('nice_to_have')->default('[]');
            $table->string('salary_range', 100)->nullable();
            $table->string('status', 50)->default('open'); // draft|open|closed|on_hold
            $table->integer('target_hires')->default(1);
            $table->integer('applicant_count')->default(0);
            $table->uuid('agent_run_id')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('department');
        });
    }
    public function down(): void { Schema::dropIfExists('job_postings'); }
};
