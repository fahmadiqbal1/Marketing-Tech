<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('generated_outputs', function (Blueprint $table) {
            $table->uuid('parent_output_id')->nullable()->after('agent_job_id');
            $table->index('parent_output_id');
        });
    }

    public function down(): void
    {
        Schema::table('generated_outputs', function (Blueprint $table) {
            $table->dropIndex(['parent_output_id']);
            $table->dropColumn('parent_output_id');
        });
    }
};
