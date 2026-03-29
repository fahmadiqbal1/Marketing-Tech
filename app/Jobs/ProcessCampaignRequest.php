<?php

namespace App\Jobs;

use App\Services\Campaign\AutonomousCampaignService;
use App\Services\Campaign\CampaignApprovalService;
use App\Services\Campaign\CampaignBrief;
use App\Services\Campaign\CampaignIntentAnalyzer;
use App\Services\Campaign\CampaignPreviewBuilder;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Main autonomous campaign orchestration pipeline.
 *
 * Flow:
 *   1. Analyze Telegram input → CampaignBrief (gpt-4o-mini, ~200ms)
 *   2. Parallel agent fan-out → content + marketing + growth (async queue)
 *   3. Poll until complete (max 120s) or timeout
 *   4. Build preview package
 *   5. Store pending campaign in Redis (24h TTL)
 *   6. Send preview + approval buttons to Telegram
 */
class ProcessCampaignRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $queue   = 'agents';
    public int    $tries   = 2;
    public int    $timeout = 300;
    public array  $backoff = [30, 60];

    public function __construct(
        private readonly int     $chatId,
        private readonly string  $instruction,
        private readonly ?string $mediaKey,     // MinIO key or null
        private readonly string  $mediaType,    // 'image' | 'video' | 'none'
    ) {}

    public function handle(
        CampaignIntentAnalyzer   $analyzer,
        AutonomousCampaignService $campaignSvc,
        CampaignPreviewBuilder   $previewBuilder,
        TelegramBotService       $telegram,
    ): void {
        Log::info('ProcessCampaignRequest: starting', [
            'chat_id'    => $this->chatId,
            'media_type' => $this->mediaType,
        ]);

        // Step 1: Analyze intent
        $brief = $analyzer->analyze($this->instruction, $this->mediaType);

        // Step 2: Fan out to agents in parallel
        $results = $campaignSvc->orchestrate($brief, null);

        // Step 3: Build preview
        $preview = $previewBuilder->build($brief, $results, null);

        // Step 4: Store in Redis for approval flow (24h TTL)
        $campaignId = (string) Str::uuid();
        Cache::put("pending_campaign:{$campaignId}", [
            'brief'    => $brief->toArray(),
            'results'  => $results,
            'preview'  => $preview,
            'chat_id'  => $this->chatId,
            'media_key'=> $this->mediaKey,
        ], now()->addHours(24));

        // Step 5: Send preview to Telegram
        $this->sendPreview($telegram, $preview, $brief, $campaignId);

        Log::info('ProcessCampaignRequest: preview sent', [
            'campaign_id' => $campaignId,
            'chat_id'     => $this->chatId,
            'platforms'   => $brief->targetPlatforms,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ProcessCampaignRequest: all retries exhausted', [
            'chat_id' => $this->chatId,
            'error'   => $e->getMessage(),
        ]);
        try {
            app(TelegramBotService::class)->sendMessage(
                $this->chatId,
                '⚠️ *Campaign generation failed* after multiple attempts. Please try again or simplify your request.'
            );
        } catch (\Throwable) {}
    }

    private function sendPreview(
        TelegramBotService $telegram,
        array              $preview,
        CampaignBrief      $brief,
        string             $campaignId,
    ): void {
        // Summary message
        $telegram->sendMessage(
            $this->chatId,
            "✅ *{$preview['headline']}*\n\n"
            . "📋 {$preview['summary']}\n"
            . "💡 *Growth tip:* {$preview['growth_tip']}"
        );

        // Per-platform variants
        foreach ($preview['variants'] as $platform => $variant) {
            $platformLabel = ucfirst($platform);
            $scheduledAt   = $preview['schedule'][$platform] ?? 'TBD';
            $telegram->sendMessage(
                $this->chatId,
                "*{$platformLabel}* (scheduled: {$scheduledAt})\n\n"
                . "{$variant['caption']}\n\n"
                . "_" . implode(' ', (array)($variant['hashtags'] ?? [])) . "_"
            );
        }

        // Approval buttons
        $telegram->sendMessage(
            $this->chatId,
            "Ready to publish? Choose an option:",
            replyMarkup: $telegram->inlineKeyboard([
                [['text' => '✅ Approve & Schedule', 'callback_data' => "campaign:approve:{$campaignId}"]],
                [['text' => '🔄 Regenerate',          'callback_data' => "campaign:regen:{$campaignId}"]],
                [['text' => '✏️ Edit Brief',           'callback_data' => "campaign:edit:{$campaignId}"]],
                [['text' => '❌ Cancel',               'callback_data' => "campaign:cancel:{$campaignId}"]],
            ])
        );
    }
}
