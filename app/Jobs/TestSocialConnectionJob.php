<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Services\Social\SocialPlatformService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TestSocialConnectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 30;

    public function __construct(public readonly SocialAccount $account)
    {
        $this->onQueue('low');
    }

    public function handle(SocialPlatformService $social): void
    {
        try {
            $result = $social->driver($this->account->platform)->testConnection($this->account);
        } catch (\Throwable $e) {
            $result = ['healthy' => false, 'error' => $e->getMessage()];
        }

        $this->account->update([
            'connection_healthy' => $result['healthy'],
            'last_tested_at'     => now(),
            'last_error'         => $result['error'] ?? null,
        ]);

        Log::info('TestSocialConnectionJob', [
            'account_id' => $this->account->id,
            'platform'   => $this->account->platform,
            'healthy'    => $result['healthy'],
        ]);
    }
}
