<?php

namespace App\Services\Campaign;

use Illuminate\Support\Facades\Log;

/**
 * Assembles a preview package from agent results for Telegram delivery.
 */
class CampaignPreviewBuilder
{
    public function build(CampaignBrief $brief, array $results, ?array $processedMedia): array
    {
        $variants  = $this->extractVariants($brief, $results);
        $schedule  = $this->extractSchedule($brief, $results);
        $growthTip = $this->extractGrowthTip($results);

        return [
            'headline'      => $this->buildHeadline($brief),
            'summary'       => $this->buildSummary($brief, $results),
            'platforms'     => $brief->targetPlatforms,
            'variants'      => $variants,
            'schedule'      => $schedule,
            'growth_tip'    => $growthTip,
            'media_key'     => $processedMedia['source_key'] ?? null,
        ];
    }

    private function buildHeadline(CampaignBrief $brief): string
    {
        $brand  = $brief->brandName ? " — {$brief->brandName}" : '';
        $type   = ucwords(str_replace('_', ' ', $brief->campaignType));
        return "{$type} Campaign{$brand}";
    }

    private function buildSummary(CampaignBrief $brief, array $results): string
    {
        $platformCount = count($brief->targetPlatforms);
        $ready         = collect($results)->filter(fn ($r) => $r['status'] === 'completed')->count();
        return "{$ready}/3 agents completed. {$platformCount} platforms targeted. Tone: {$brief->tone}.";
    }

    private function extractVariants(CampaignBrief $brief, array $results): array
    {
        $variants = [];

        // Try to extract structured variants from ContentAgent result
        $contentOutput = $results['content']['output'] ?? '';
        if ($contentOutput) {
            // Look for JSON variants block in agent output
            preg_match('/```(?:json)?\s*(\{[^`]+\})\s*```/s', $contentOutput, $m);
            if (isset($m[1])) {
                $decoded = json_decode($m[1], true);
                if (is_array($decoded)) {
                    foreach ($brief->targetPlatforms as $platform) {
                        if (isset($decoded[$platform])) {
                            $raw = $decoded[$platform]['hashtags'] ?? [];
                            $decoded[$platform]['hashtags'] = is_array($raw)
                                ? $raw
                                : array_values(array_filter(preg_split('/[\s,]+/', ltrim((string)$raw, '#'))));
                            $variants[$platform] = $decoded[$platform];
                        }
                    }
                }
            }
        }

        // Fallback: generate placeholder variants for any missing platforms
        foreach ($brief->targetPlatforms as $platform) {
            if (!isset($variants[$platform])) {
                $variants[$platform] = [
                    'caption'    => $brief->keyMessage . ' ' . $brief->cta,
                    'hashtags'   => ['#' . str_replace('_', '', $brief->campaignType), '#campaign'],
                    'char_count' => strlen($brief->keyMessage) + strlen($brief->cta) + 1,
                ];
            }
        }

        return $variants;
    }

    private function extractSchedule(CampaignBrief $brief, array $results): array
    {
        $schedule = [];
        foreach ($brief->targetPlatforms as $platform) {
            $schedule[$platform] = $brief->getOptimalPostTime($platform)->toDateTimeString();
        }
        return $schedule;
    }

    private function extractGrowthTip(array $results): string
    {
        $growthOutput = $results['growth']['output'] ?? '';
        if (!$growthOutput) {
            return 'Post consistently for the first 48 hours to maximise algorithmic distribution.';
        }

        // Extract first actionable sentence from growth agent output
        $sentences = preg_split('/(?<=[.!?])\s+/', strip_tags($growthOutput));
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (strlen($sentence) > 30 && strlen($sentence) < 200) {
                return $sentence;
            }
        }

        return 'Post consistently for the first 48 hours to maximise algorithmic distribution.';
    }
}
