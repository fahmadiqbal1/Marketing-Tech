<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Encrypt existing plaintext access_token / refresh_token values in social_accounts.
 *
 * Laravel's 'encrypted' cast uses Crypt::encryptString() transparently on read/write
 * once added to the model. This migration re-writes any existing plaintext rows so
 * they are stored as ciphertext before the cast is in effect at the DB level.
 *
 * Safe to run on an empty table (no-op) or after the cast is already applied
 * (Crypt::decryptString on already-encrypted values will throw — we detect and skip).
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('social_accounts')
            ->whereNotNull('access_token')
            ->orWhereNotNull('refresh_token')
            ->get(['id', 'access_token', 'refresh_token']);

        foreach ($rows as $row) {
            $accessToken  = $this->encryptIfPlaintext($row->access_token);
            $refreshToken = $this->encryptIfPlaintext($row->refresh_token);

            DB::table('social_accounts')->where('id', $row->id)->update([
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
            ]);
        }

        Log::info('[Migration] encrypt_social_account_tokens: processed ' . $rows->count() . ' rows.');
    }

    public function down(): void
    {
        // Decrypt ciphertext back to plaintext (for rollback scenarios)
        $rows = DB::table('social_accounts')
            ->whereNotNull('access_token')
            ->orWhereNotNull('refresh_token')
            ->get(['id', 'access_token', 'refresh_token']);

        foreach ($rows as $row) {
            $accessToken  = $this->decryptIfEncrypted($row->access_token);
            $refreshToken = $this->decryptIfEncrypted($row->refresh_token);

            DB::table('social_accounts')->where('id', $row->id)->update([
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
            ]);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function encryptIfPlaintext(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // If already encrypted (starts with eyJ — base64 JSON envelope), skip
        try {
            Crypt::decryptString($value);
            return $value; // already encrypted
        } catch (\Throwable) {
            return Crypt::encryptString($value);
        }
    }

    private function decryptIfEncrypted(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return $value; // already plaintext
        }
    }
};
