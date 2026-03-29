<?php

namespace App\Jobs;

use App\Models\KnowledgeBase;
use App\Models\SocialAccount;
use App\Services\AI\AIRouter;
use App\Services\Social\SocialPlatformService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EvaluateSocialAccount implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int    $tries   = 2;
    public int    $timeout = 90;
    public string $queue   = 'social';

    public function __construct(
        private readonly string $accountId,
        private readonly string $platform,
    ) {}

    public function handle(AIRouter $ai, SocialPlatformService $socialService): void
    {
        $account = SocialAccount::find($this->accountId);
        if (! $account || ! $account->is_connected) {
            return;
        }

        try {
            $driver = $socialService->driver($this->platform);
        } catch (\Throwable) {
            return;
        }

        $posts = $driver->getRecentPosts($account, 20);

        if (empty($posts)) {
            Log::info('EvaluateSocialAccount: no posts found', [
                'account_id' => $this->accountId,
                'platform'   => $this->platform,
            ]);
            return;
        }

        $postSummary = collect($posts)->map(function ($post, $i) {
            $text    = mb_substr($post['text'] ?? '', 0, 200);
            $metrics = collect($post)->except(['id', 'text', 'created_at', 'type'])
                ->map(fn($v, $k) => "{$k}: {$v}")
                ->join(', ');
            return ($i + 1) . ". \"{$text}\" [{$metrics}]";
        })->join("\n");

        $prompt = <<<PROMPT
You are a social media analyst. Analyse these recent {$this->platform} posts and provide a concise brand performance summary.

Posts (most recent first):
{$postSummary}

Provide:
1. Overall engagement quality (high/medium/low) with brief rationale
2. Top 3 content themes that perform best
3. Posting frequency assessment
4. 3 specific actionable recommendations to improve performance

Keep the response under 400 words. Be direct and data-driven.
PROMPT;

        $analysis = $ai->complete($prompt, 'gpt-4o-mini', 600, 0.3);

        if (empty(trim($analysis ?? ''))) {
            return;
        }

        $handle = $account->handle ?? $account->platform_user_id ?? $this->platform;

        KnowledgeBase::create([
            'title'       => "Brand Analysis: {$this->platform} — {$handle}",
            'content'     => $analysis,
            'category'    => 'brand',
            'tags'        => [$this->platform, 'brand-analysis', 'auto-generated'],
            'business_id' => $account->business_id,
        ]);

        Log::info('EvaluateSocialAccount: analysis stored', [
            'account_id' => $this->accountId,
            'platform'   => $this->platform,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('EvaluateSocialAccount failed', [
            'account_id' => $this->accountId,
            'platform'   => $this->platform,
            'error'      => $e->getMessage(),
        ]);
    }
}
