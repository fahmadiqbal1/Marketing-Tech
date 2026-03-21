<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('content_performance', function (Blueprint $table) {
            $table->id();
            $table->uuid('content_variation_id');
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('conversions')->default(0);
            $table->decimal('ctr', 7, 4)->default(0);        // clicks / impressions
            // score = conversions*10 + clicks*2 + impressions*0.1
            $table->decimal('score', 12, 4)->default(0);
            $table->string('source', 50)->default('manual'); // manual, webhook, simulated
            $table->timestamp('recorded_at')->useCurrent();

            $table->foreign('content_variation_id')
                ->references('id')->on('content_variations')->cascadeOnDelete();
            $table->index(['content_variation_id', 'recorded_at']);
            $table->index('score');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_performance');
    }
};
