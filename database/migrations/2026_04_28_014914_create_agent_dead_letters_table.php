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
        Schema::create('agent_dead_letters', function (Blueprint $table) {
            $table->id();
            $table->uuid('agent_job_id')->nullable()->index();
            $table->string('agent_type', 64);
            $table->string('reason', 128);
            $table->unsignedSmallInteger('last_step')->default(0);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_dead_letters');
    }
};
