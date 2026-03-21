<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('content_variations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('agent_job_id');
            $table->char('variation_label', 1);       // A, B, C
            $table->longText('content');
            $table->jsonb('metadata')->default('{}'); // tone, hook_type, structure, word_count
            $table->boolean('is_winner')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('agent_job_id')->references('id')->on('agent_jobs')->cascadeOnDelete();
            $table->index(['agent_job_id', 'variation_label']);
            $table->index('is_winner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_variations');
    }
};
