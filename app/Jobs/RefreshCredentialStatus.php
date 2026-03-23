<?php

namespace App\Jobs;

use App\Models\SocialCredential;
use App\Models\SystemEvent;
use App\Services\Social\SocialPlatformService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RefreshCredentialStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $queue = 'low';

    public function handle(SocialPlatformService $social): void
    {
        $credentials = SocialCredential::where('is_active', true)->get();

        foreach ($credentials as $cred) {
            try {
                $result = $social->driver($cred->platform)->validateCredentials(
                    $cred->client_id,
                    $cred->client_secret
                );

                if ($result['ok']) {
                    $cred->update([
                        'last_tested_at' => now(),
                        'last_test_error' => null,
                    ]);
                } else {
                    $cred->update([
                        'is_active'      => false,
                        'last_test_error' => $result['error'] ?? 'Validation failed',
                    ]);

                    SystemEvent::create([
                        'event_type'  => 'credential_check_failed',
                        'severity'    => 'warning',
                        'source'      => 'refresh_credential_status',
                        'entity_id'   => (string) $cred->id,
                        'entity_type' => 'social_credential',
                        'message'     => "Credential check failed for {$cred->platform}: " . ($result['error'] ?? 'unknown error'),
                        'occurred_at' => now(),
                    ]);

                    Log::warning("RefreshCredentialStatus: {$cred->platform} credentials failed validation", [
                        'error' => $result['error'] ?? null,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error("RefreshCredentialStatus: exception for {$cred->platform}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
