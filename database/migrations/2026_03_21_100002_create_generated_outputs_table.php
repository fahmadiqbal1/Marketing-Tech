<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('generated_outputs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('agent_job_id');
            // content, strategy, analysis, creative, report, campaign, other
            $table->string('type', 50)->default('content');
            $table->longText('content');
            $table->unsignedSmallInteger('version')->default(1);
            $table->boolean('is_winner')->default(false);
            $table->jsonb('metadata')->default('{}');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('agent_job_id')->references('id')->on('agent_jobs')->cascadeOnDelete();
            $table->index(['agent_job_id', 'type']);
            $table->index(['type', 'is_winner']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_outputs');
    }
};
