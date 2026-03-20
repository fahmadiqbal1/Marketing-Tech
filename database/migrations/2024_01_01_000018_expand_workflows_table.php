<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        Schema::table('workflows', function (Blueprint $table) {
            // Rename instruction -> keep it, add input_payload as jsonb alias
            $table->jsonb('input_payload')->default('{}')->after('status');
            $table->text('description')->nullable()->after('name');
            $table->jsonb('output')->default('{}')->after('plan');
            $table->integer('priority')->default(5)->after('max_retries');
            $table->string('current_task_id', 36)->nullable()->after('priority');
            $table->string('approved_by', 100)->nullable()->after('approval_granted');
            $table->timestamp('scheduled_at')->nullable()->after('approved_by');
        });

        // Copy instruction into input_payload for existing rows
        DB::statement("UPDATE workflows SET input_payload = jsonb_build_object('instruction', instruction) WHERE instruction IS NOT NULL");

        // Add composite indexes
        DB::statement('CREATE INDEX IF NOT EXISTS workflows_status_priority_idx ON workflows (status, priority, scheduled_at)');
    }

    public function down(): void {
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropColumn(['input_payload','description','output','priority','current_task_id','approved_by','scheduled_at']);
        });
    }
};
