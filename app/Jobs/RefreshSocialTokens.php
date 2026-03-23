<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Models\SystemEvent;
use App\Services\Social\SocialPlatformService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RefreshSocialTokens implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int    $tries   = 2;
    public int    $timeout = 60;
    public $queue   = 'low';

    public function handle(SocialPlatformService $social): void
    {
        // Find connected accounts whose tokens expire within 24 hours
        $expiring = SocialAccount::tokenExpiringSoon()->get();

        if ($expiring->isEmpty()) {
            return;
        }

        Log::info("RefreshSocialTokens: refreshing {$expiring->count()} tokens");

        foreach ($expiring as $account) {
            try {
                $driver = $social->driver($account->platform);

                if (! $driver->isConfigured()) {
                    Log::info("RefreshSocialTokens: {$account->platform} is not configured for real refresh (missing API credentials in .env)");
                    continue;
                }

                $refreshed = $driver->refreshToken($account);

                if ($refreshed->is_connected) {
                    SystemEvent::create([
                        'level'   => 'info',
                        'message' => "Token refreshed: {$account->platform} @{$account->handle}. Expires: {$refreshed->token_expires_at?->toDateTimeString()}",
                    ]);
                } else {
                    // Token refresh failed — account marked disconnected in refreshToken()
                    SystemEvent::create([
                        'level'   => 'error',
                        'message' => "Token refresh FAILED: {$account->platform} @{$account->handle}. Account disconnected. Error: {$refreshed->last_error}",
                    ]);

                    Log::error("RefreshSocialTokens: token refresh failed for {$account->platform} {$account->handle}", [
                        'error' => $refreshed->last_error,
                    ]);
                }

            } catch (\Throwable $e) {
                // Mark account as disconnected on unexpected failure
                $account->update([
                    'is_connected' => false,
                    'last_error'   => $e->getMessage(),
                ]);

                SystemEvent::create([
                    'level'   => 'error',
                    'message' => "Token refresh exception: {$account->platform} @{$account->handle} — {$e->getMessage()}",
                ]);

                Log::error("RefreshSocialTokens: exception for account {$account->id}", ['error' => $e->getMessage()]);
            }
        }
    }
}
