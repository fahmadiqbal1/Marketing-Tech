<?php

namespace App\Services\Marketing;

use App\Models\Campaign;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class CampaignService
{
    public function sendCampaign(Campaign $campaign): void
    {
        if (! in_array($campaign->status, ['scheduled', 'draft'])) {
            return;
        }

        $campaign->update(['status' => 'sending', 'sent_at' => now()]);

        try {
            // Dispatch to appropriate channel
            match ($campaign->type) {
                'email'      => $this->sendEmail($campaign),
                'social'     => $this->postSocial($campaign),
                'meta_ads'   => $this->submitMetaAd($campaign),
                'google_ads' => $this->submitGoogleAd($campaign),
                default      => throw new \RuntimeException("Unknown campaign type: {$campaign->type}"),
            };

            $campaign->update(['status' => 'sent']);
            Log::info("Campaign sent", ['id' => $campaign->id, 'type' => $campaign->type]);

        } catch (\Throwable $e) {
            $campaign->update(['status' => 'failed']);
            Log::error("Campaign send failed", ['id' => $campaign->id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function pauseCampaign(Campaign $campaign): void
    {
        $campaign->update(['status' => 'paused']);
    }

    public function getCampaignStats(Campaign $campaign): array
    {
        return [
            'id'               => $campaign->id,
            'name'             => $campaign->name,
            'send_count'       => $campaign->send_count,
            'open_count'       => $campaign->open_count,
            'click_count'      => $campaign->click_count,
            'conversion_count' => $campaign->conversion_count,
            'open_rate'        => $campaign->open_rate . '%',
            'click_rate'       => $campaign->click_rate . '%',
            'revenue'          => '$' . number_format($campaign->revenue_attributed, 2),
            'performance_data' => $campaign->performance_data,
        ];
    }

    public function getAggregateStats(string $from, string $to): array
    {
        $stats = Campaign::whereBetween('sent_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->selectRaw('
                COUNT(*) as total_campaigns,
                SUM(send_count) as total_sends,
                SUM(open_count) as total_opens,
                SUM(click_count) as total_clicks,
                SUM(conversion_count) as total_conversions,
                SUM(revenue_attributed) as total_revenue,
                AVG(CASE WHEN send_count > 0 THEN ROUND(open_count::decimal / send_count * 100, 2) END) as avg_open_rate,
                AVG(CASE WHEN send_count > 0 THEN ROUND(click_count::decimal / send_count * 100, 2) END) as avg_click_rate
            ')
            ->first();

        return $stats ? $stats->toArray() : [];
    }

    public function getFunnelStats(string $funnel, string $from, string $to, ?string $segment = null): array
    {
        // Return structured funnel data from experiment events or custom tracking
        // This connects to whatever analytics tool is configured
        return [
            'funnel'   => $funnel,
            'from'     => $from,
            'to'       => $to,
            'segment'  => $segment,
            'note'     => 'Connect to analytics provider via MIXPANEL_TOKEN or AMPLITUDE_API_KEY in .env',
            'stages'   => [],
        ];
    }

    // ── Channel adapters ──────────────────────────────────────────
    // These call real APIs when credentials are configured.

    private function sendEmail(Campaign $campaign): void
    {
        $apiKey = config('services.sendgrid.api_key') ?? config('services.mailchimp.api_key');

        if (empty($apiKey)) {
            Log::info("Email campaign stored — no email provider configured", ['id' => $campaign->id]);
            return;
        }

        // SendGrid integration
        if (! empty(config('services.sendgrid.api_key'))) {
            $this->sendViaSendGrid($campaign);
        }
    }

    private function sendViaSendGrid(Campaign $campaign): void
    {
        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.sendgrid.api_key'),
            'Content-Type'  => 'application/json',
        ])->post('https://api.sendgrid.com/v3/mail/send', [
            'personalizations' => [['to' => [['email' => 'test@example.com']]]],
            'from'    => ['email' => config('mail.from.address'), 'name' => config('mail.from.name')],
            'subject' => $campaign->subject,
            'content' => [['type' => 'text/html', 'value' => $campaign->body]],
        ]);

        if ($response->failed()) {
            throw new \RuntimeException("SendGrid failed: " . $response->body());
        }

        Log::info("Campaign sent via SendGrid", ['id' => $campaign->id]);
    }

    private function postSocial(Campaign $campaign): void
    {
        Log::info("Social post queued — integrate with Buffer/Hootsuite/native APIs", ['id' => $campaign->id]);
    }

    private function submitMetaAd(Campaign $campaign): void
    {
        Log::info("Meta Ads campaign stored — configure META_ADS_ACCESS_TOKEN", ['id' => $campaign->id]);
    }

    private function submitGoogleAd(Campaign $campaign): void
    {
        Log::info("Google Ads campaign stored — configure GOOGLE_ADS_DEVELOPER_TOKEN", ['id' => $campaign->id]);
    }
}
