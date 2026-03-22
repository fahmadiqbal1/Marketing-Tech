<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_jobs', function (Blueprint $table) {
            // Nullable for backward compatibility — existing jobs will have NULL
            // Tool gating falls back to keyword regex when NULL
            $table->string('task_type')->nullable()->after('agent_class');
            $table->index('task_type');
        });
    }

    public function down(): void
    {
        Schema::table('agent_jobs', function (Blueprint $table) {
            $table->dropIndex(['task_type']);
            $table->dropColumn('task_type');
        });
    }
};
