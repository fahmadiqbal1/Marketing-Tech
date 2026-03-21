<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_performance', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_task_id');
            $table->string('campaign_type', 50); // organic|paid_search|paid_social|email|content|paid_ads_planned
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('conversions')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->decimal('revenue_estimate', 12, 2)->default(0);
            $table->decimal('roi', 8, 4)->default(0); // computed in PHP: (revenue - cost) / cost * 100
            $table->timestamp('created_at')->useCurrent();

            $table->index('agent_task_id');
            $table->index('campaign_type');
            $table->index(['agent_task_id', 'campaign_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_performance');
    }
};
