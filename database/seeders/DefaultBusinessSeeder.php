<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DefaultBusinessSeeder extends Seeder
{
    /**
     * Create the default Business + superadmin User from env vars and backfill
     * existing tenant rows with the default business_id.
     *
     * Required env vars: ADMIN_EMAIL, ADMIN_PASSWORD, ADMIN_BUSINESS_NAME
     * Safe to run multiple times (idempotent via firstOrCreate).
     */
    public function run(): void
    {
        $businessName = env('ADMIN_BUSINESS_NAME', 'Default Business');
        $adminEmail   = env('ADMIN_EMAIL',          'admin@example.com');
        $adminPassword= env('ADMIN_PASSWORD',        'password');

        // 1. Create or find the default business
        $business = Business::firstOrCreate(
            ['slug' => Str::slug($businessName)],
            [
                'name'      => $businessName,
                'settings'  => [],
                'is_active' => true,
            ]
        );

        $this->command->info("Business: [{$business->id}] {$business->name}");

        // 2. Create or find the superadmin user
        $user = User::firstOrCreate(
            ['email' => $adminEmail],
            [
                'name'        => 'Admin',
                'password'    => Hash::make($adminPassword),
                'business_id' => $business->id,
                'role'        => 'superadmin',
            ]
        );

        // Ensure the user is linked to the default business
        if (! $user->business_id) {
            $user->update(['business_id' => $business->id, 'role' => 'superadmin']);
        }

        $this->command->info("User: [{$user->id}] {$user->email} ({$user->role})");

        // 3. Backfill all existing tenant rows that have NULL business_id
        $tables = [
            'social_accounts',
            'content_calendar',
            'campaigns',
            'knowledge_base',
            'hashtag_sets',
            'agent_jobs',
            'candidates',
            'job_postings',
        ];

        foreach ($tables as $table) {
            try {
                $updated = DB::table($table)
                    ->whereNull('business_id')
                    ->update(['business_id' => $business->id]);

                $this->command->info("  {$table}: backfilled {$updated} row(s)");
            } catch (\Throwable $e) {
                // Table may not have the column yet if migration hasn't run
                $this->command->warn("  {$table}: skipped — {$e->getMessage()}");
            }
        }

        $this->command->info('DefaultBusinessSeeder complete.');
    }
}
