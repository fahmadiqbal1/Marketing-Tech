<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE social_accounts DROP CONSTRAINT IF EXISTS social_accounts_platform_check");
        DB::statement("ALTER TABLE social_accounts ADD CONSTRAINT social_accounts_platform_check
            CHECK (platform IN ('tiktok','instagram','facebook','twitter','linkedin','youtube'))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE social_accounts DROP CONSTRAINT IF EXISTS social_accounts_platform_check");
        DB::statement("ALTER TABLE social_accounts ADD CONSTRAINT social_accounts_platform_check
            CHECK (platform IN ('tiktok','instagram','facebook','twitter','linkedin'))");
    }
};
