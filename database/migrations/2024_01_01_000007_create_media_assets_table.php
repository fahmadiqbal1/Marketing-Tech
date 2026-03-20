<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workflow_id')->nullable();
            $table->string('original_name', 500);
            $table->string('storage_key', 500);
            $table->string('storage_bucket', 100)->default('ops-media');
            $table->string('mime_type', 100);
            $table->string('extension', 20);
            $table->bigInteger('file_size_bytes');
            $table->string('status', 50)->default('queued'); // queued|scanning|processing|ready|failed|rejected
            $table->boolean('virus_clean')->nullable();
            $table->string('clamav_result', 255)->nullable();
            $table->jsonb('metadata')->default('{}');       // width,height,duration,codec,etc
            $table->text('extracted_text')->nullable();     // from OCR
            $table->string('content_category', 100)->nullable(); // ai-classified content type
            $table->jsonb('processing_log')->default('[]');
            $table->string('processed_key', 500)->nullable(); // after transcoding/resizing
            $table->string('thumbnail_key', 500)->nullable();
            $table->bigInteger('uploaded_by_user_id')->nullable();
            $table->bigInteger('uploaded_via_chat_id')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('mime_type');
            $table->index('workflow_id');
            $table->index('created_at');
        });
    }
    public function down(): void { Schema::dropIfExists('media_assets'); }
};
