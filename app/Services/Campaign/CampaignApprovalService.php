<?php

namespace App\Services\Campaign;

use App\Models\ContentCalendar;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Handles inline button callbacks for pending campaign previews.
 * approve → creates ContentCalendar entries per platform
 * regenerate → re-dispatches ProcessCampaignRequest with a creativity nudge
 * requestEdit → prompts user to send back changes via Telegram
 */
class CampaignApprovalService
{
    private function telegram(): TelegramBotService
    {
        return app(TelegramBotService::class);
    }

    public function approve(string $campaignId, int $chatId, int $msgId): void
    {
        $pending = Cache::get("pending_campaign:{$campaignId}");
        if (!$pending) {
            $this->telegram()->sendMessage($chatId,
                '⚠️ Campaign draft expired (24h limit). Please resend your request.');
            return;
        }

        $brief   = CampaignBrief::fromArray($pending['brief']);
        $preview = $pending['preview'] ?? [];
        $created = [];

        foreach ($brief->targetPlatforms as $platform) {
            $variant = $preview['variants'][$platform] ?? null;
            if (!$variant) continue;

            try {
                ContentCalendar::create([
                    'title'             => $brief->keyMessage,
                    'platform'          => $platform,
                    'content_type'      => $brief->getFormatForPlatform($platform),
                    'draft_content'     => $variant['caption'],
                    'status'            => 'scheduled',
                    'moderation_status' => 'auto_approved',
                    'scheduled_at'      => $brief->getOptimalPostTime($platform),
                    'hashtags'          => $variant['hashtags'] ?? '',
                    'metadata'          => [
                        'campaign_id' => $campaignId,
                        'campaign_type' => $brief->campaignType,
                        'tone'          => $brief->tone,
                        'source'        => 'telegram_campaign',
                    ],
                ]);
                $created[] = $platform;
            } catch (\Throwable $e) {
                Log::warning('CampaignApprovalService: failed to create calendar entry', [
                    'platform'    => $platform,
                    'campaign_id' => $campaignId,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        Cache::forget("pending_campaign:{$campaignId}");

        $this->telegram()->editMessage(
            $chatId, $msgId,
            "✅ *Scheduled!* Content queued for: " . implode(', ', $created) . "\n"
            . "Posts will go live at optimal times. Use /jobs to monitor progress."
        );

        Log::info('CampaignApprovalService: campaign approved', [
            'campaign_id' => $campaignId,
            'platforms'   => $created,
        ]);
    }

    public function regenerate(string $campaignId, int $chatId, int $msgId): void
    {
        $pending = Cache::get("pending_campaign:{$campaignId}");
        if (!$pending) {
            $this->telegram()->editMessage($chatId, $msgId,
                '⚠️ Campaign draft expired. Please resend your original request.');
            return;
        }

        $this->telegram()->editMessage($chatId, $msgId, '🔄 Regenerating with a different creative direction...');
        Cache::forget("pending_campaign:{$campaignId}");

        // Re-dispatch with a creativity nudge appended to the key message
        \App\Jobs\ProcessCampaignRequest::dispatch(
            $chatId,
            $pending['brief']['key_message'] . ' — try a completely different creative direction',
            $pending['preview']['media_key'] ?? null,
            'none',
        )->onQueue('agents');
    }

    public function requestEdit(string $campaignId, int $chatId, int $msgId): void
    {
        $this->telegram()->editMessage(
            $chatId, $msgId,
            "✏️ *Edit mode* — Reply with your changes:\n\n"
            . "e.g. \"Make the tone more casual\" or \"Change the CTA to 'Visit us now'\" or \"Target LinkedIn and Twitter only\"\n\n"
            . "Your campaign draft is saved for 24 hours."
        );
    }
}
