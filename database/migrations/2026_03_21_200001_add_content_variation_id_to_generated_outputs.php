<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_outputs', function (Blueprint $table) {
            $table->uuid('content_variation_id')->nullable()->after('parent_output_id');
            $table->index('content_variation_id');
        });
    }

    public function down(): void
    {
        Schema::table('generated_outputs', function (Blueprint $table) {
            $table->dropIndex(['content_variation_id']);
            $table->dropColumn('content_variation_id');
        });
    }
};
