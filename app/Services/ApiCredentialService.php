<?php

namespace App\Services;

use App\Models\ApiCredential;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Manages API credentials in the database instead of .env.
 * Uses a 5-minute cache to avoid a DB hit on every agent step.
 */
class ApiCredentialService
{
    private const CACHE_TTL = 300; // 5 minutes

    private static function cacheKey(string $envKey): string
    {
        return 'api_cred:' . $envKey;
    }

    /**
     * Store an API key securely (encrypted) in the database.
     * Immediately invalidates the cache for that key.
     */
    public function store(string $provider, string $envKey, string $plainValue): void
    {
        ApiCredential::updateOrCreate(
            ['provider' => $provider, 'env_key' => $envKey],
            [
                'encrypted_value' => Crypt::encryptString($plainValue),
                'is_valid'        => true,
                'validated_at'    => null,
            ]
        );

        Cache::forget(self::cacheKey($envKey));
    }

    /**
     * Retrieve a plain-text API key.
     * Order: DB (cached 5min) → env() fallback.
     */
    public function retrieve(string $envKey): ?string
    {
        return Cache::remember(self::cacheKey($envKey), self::CACHE_TTL, function () use ($envKey) {
            try {
                $credential = ApiCredential::where('env_key', $envKey)
                    ->where('is_valid', true)
                    ->first();

                if ($credential) {
                    return Crypt::decryptString($credential->encrypted_value);
                }
            } catch (\Throwable $e) {
                Log::warning("[ApiCredentialService] Failed to decrypt credential for {$envKey}: " . $e->getMessage());
            }

            // Fall back to environment variable
            return env($envKey) ?: null;
        });
    }

    /**
     * Mark a credential as invalid (e.g. after an API call returns 401).
     */
    public function invalidate(string $envKey): void
    {
        ApiCredential::where('env_key', $envKey)->update(['is_valid' => false]);
        Cache::forget(self::cacheKey($envKey));
    }

    /**
     * Check whether a credential exists (in DB or env).
     */
    public function exists(string $envKey): bool
    {
        return ! empty($this->retrieve($envKey));
    }

    /**
     * List all stored providers and their key names (values masked).
     */
    public function list(): array
    {
        return ApiCredential::all(['provider', 'env_key', 'is_valid', 'validated_at', 'updated_at'])
            ->toArray();
    }
}
