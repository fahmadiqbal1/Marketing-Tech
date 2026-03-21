<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent repair migration: ensures agent_job_id exists on agent_steps.
 *
 * The primary migration (2026_03_21_081453) adds this column, but on partial
 * deployments it may have been skipped. This migration is safe to run multiple
 * times — Schema::hasColumn() guards all operations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_steps', function (Blueprint $table) {
            if (! Schema::hasColumn('agent_steps', 'agent_job_id')) {
                $table->uuid('agent_job_id')->nullable()->after('task_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('agent_steps', function (Blueprint $table) {
            if (Schema::hasColumn('agent_steps', 'agent_job_id')) {
                $table->dropIndex(['agent_job_id']);
                $table->dropColumn('agent_job_id');
            }
        });
    }
};
