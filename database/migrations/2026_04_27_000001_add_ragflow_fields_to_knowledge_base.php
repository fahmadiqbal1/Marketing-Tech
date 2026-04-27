<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_base', function (Blueprint $table) {
            $table->string('ragflow_dataset_id')->nullable()->after('embedding');
            $table->string('ragflow_doc_id')->nullable()->after('ragflow_dataset_id');
            $table->index('ragflow_dataset_id');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_base', function (Blueprint $table) {
            $table->dropIndex(['ragflow_dataset_id']);
            $table->dropColumn(['ragflow_dataset_id', 'ragflow_doc_id']);
        });
    }
};
