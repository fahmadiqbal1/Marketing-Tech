<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->boolean('connection_healthy')->default(false)->after('is_connected');
            $table->timestamp('last_tested_at')->nullable()->after('connection_healthy');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn(['connection_healthy', 'last_tested_at']);
        });
    }
};
