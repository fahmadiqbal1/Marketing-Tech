<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('skills_registry', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100)->unique();           // snake_case skill name
            $table->string('class', 255);                   // FQCN of skill class
            $table->string('category', 100);                // media|content|hiring|marketing|growth|ai
            $table->text('description');
            $table->jsonb('input_schema')->default('{}');   // JSON Schema for input validation
            $table->jsonb('output_schema')->default('{}');
            $table->jsonb('required_permissions')->default('[]');
            $table->jsonb('required_services')->default('[]'); // e.g. ["ffmpeg","clamav"]
            $table->string('queue', 50)->default('default');
            $table->integer('timeout_seconds')->default(120);
            $table->integer('max_retries')->default(3);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_async')->default(false);    // runs inline or on queue
            $table->integer('usage_count')->default(0);
            $table->decimal('avg_duration_ms', 10, 2)->nullable();
            $table->timestamps();

            $table->index('category');
            $table->index('is_active');
            $table->index('name');
        });
    }
    public function down(): void { Schema::dropIfExists('skills_registry'); }
};
