<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('agent_jobs', function (Blueprint $table) {
            $table->string('ai_provider', 50)->nullable()->after('agent_class');
            $table->string('model', 100)->nullable()->after('ai_provider');
            $table->integer('total_tokens')->default(0)->after('steps_taken');
        });
    }

    public function down(): void
    {
        Schema::table('agent_jobs', function (Blueprint $table) {
            $table->dropColumn(['ai_provider', 'model', 'total_tokens']);
        });
    }
};
