<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_base', function (Blueprint $table) {
            $table->index('deleted_at', 'kb_deleted_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_base', function (Blueprint $table) {
            $table->dropIndex('kb_deleted_at_idx');
        });
    }
};
