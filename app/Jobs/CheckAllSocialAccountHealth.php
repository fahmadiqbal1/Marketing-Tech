<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckAllSocialAccountHealth implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(): void
    {
        SocialAccount::where('is_connected', true)
            ->each(function (SocialAccount $account, int $i) {
                TestSocialConnectionJob::dispatch($account)->delay($i * 3);
            });
    }
}
