<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('agent_jobs', function (Blueprint $table) {
            // Loose coupling — no FK so campaigns can be deleted independently
            $table->uuid('campaign_id')->nullable()->after('workflow_id');
            $table->index('campaign_id');
        });
    }

    public function down(): void
    {
        Schema::table('agent_jobs', function (Blueprint $table) {
            $table->dropIndex(['campaign_id']);
            $table->dropColumn('campaign_id');
        });
    }
};
