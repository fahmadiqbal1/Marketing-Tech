<?php

namespace App\Services;

use App\Models\AgentJob;
use App\Models\ContentVariation;
use App\Models\GeneratedOutput;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * CampaignContextService — gives agents memory across runs within a campaign.
 *
 * When an agent job has a campaign_id, this service is called from BaseAgent to
 * inject a summary of prior work into the initial prompt context. This allows
 * the agent to:
 *  - avoid repeating itself
 *  - reference earlier decisions
 *  - build on the best-performing assets
 */
class CampaignContextService
{
    private const CACHE_TTL = 120; // 2 minutes

    // ─── Context Building ─────────────────────────────────────────────

    /**
     * Return a formatted string summarising the campaign history.
     * Returns empty string if no prior jobs exist for the campaign.
     */
    public function getCampaignContext(string $campaignId): string
    {
        $cacheKey = "campaign:context:{$campaignId}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($campaignId) {
            try {
                return $this->buildContext($campaignId);
            } catch (\Throwable $e) {
                Log::warning('CampaignContextService: failed to build context', [
                    'campaign_id' => $campaignId,
                    'error'       => $e->getMessage(),
                ]);
                return '';
            }
        });
    }

    private function buildContext(string $campaignId): string
    {
        $jobs = AgentJob::where('campaign_id', $campaignId)
            ->whereIn('status', ['completed', 'running'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'agent_type', 'instruction', 'result', 'steps_taken', 'created_at']);

        if ($jobs->isEmpty()) {
            return '';
        }

        $lines = ["[CAMPAIGN HISTORY — prior work in this campaign]"];

        foreach ($jobs as $job) {
            $summary = Str::limit($job->result ?? 'In progress…', 200);
            $lines[] = sprintf(
                '  [%s] %s agent | Task: "%s" | Result: %s',
                $job->created_at?->format('M d H:i') ?? '?',
                ucfirst($job->agent_type),
                Str::limit($job->instruction, 80),
                $summary,
            );
        }

        // Append best-performing outputs
        $winners = $this->getBestPerformingAssets($campaignId, limit: 3);
        if (! empty($winners)) {
            $lines[] = "\n[BEST PERFORMING ASSETS in this campaign]";
            foreach ($winners as $asset) {
                $lines[] = "  - [{$asset['type']}] " . Str::limit($asset['content'], 120);
            }
        }

        return implode("\n", $lines);
    }

    // ─── History & Assets ─────────────────────────────────────────────

    /**
     * Return recent AgentJobs for a campaign.
     */
    public function getCampaignHistory(string $campaignId): array
    {
        try {
            return AgentJob::where('campaign_id', $campaignId)
                ->orderBy('created_at', 'desc')
                ->limit(20)
                ->get([
                    'id', 'agent_type', 'ai_provider', 'instruction',
                    'status', 'steps_taken', 'total_tokens', 'created_at', 'completed_at',
                ])
                ->map(fn($j) => [
                    'id'           => $j->id,
                    'agent_type'   => $j->agent_type,
                    'ai_provider'  => $j->ai_provider,
                    'instruction'  => $j->instruction,
                    'status'       => $j->status,
                    'steps_taken'  => $j->steps_taken,
                    'total_tokens' => $j->total_tokens,
                    'created_at'   => $j->created_at?->toIso8601String(),
                    'completed_at' => $j->completed_at?->toIso8601String(),
                ])
                ->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Return winner-flagged ContentVariations and GeneratedOutputs for a campaign.
     */
    public function getBestPerformingAssets(string $campaignId, int $limit = 5): array
    {
        $assets = [];

        try {
            // Winner variations
            $variations = ContentVariation::query()
                ->join('agent_jobs', 'content_variations.agent_job_id', '=', 'agent_jobs.id')
                ->where('agent_jobs.campaign_id', $campaignId)
                ->where('content_variations.is_winner', true)
                ->limit($limit)
                ->get(['content_variations.id', 'content_variations.variation_label',
                       'content_variations.content', 'content_variations.metadata',
                       'content_variations.created_at']);

            foreach ($variations as $v) {
                $assets[] = [
                    'type'       => 'variation_' . $v->variation_label,
                    'content'    => $v->content,
                    'metadata'   => $v->metadata,
                    'created_at' => $v->created_at?->toIso8601String(),
                ];
            }

            // Winner outputs
            $outputs = GeneratedOutput::query()
                ->join('agent_jobs', 'generated_outputs.agent_job_id', '=', 'agent_jobs.id')
                ->where('agent_jobs.campaign_id', $campaignId)
                ->where('generated_outputs.is_winner', true)
                ->orderByDesc('generated_outputs.created_at')
                ->limit($limit)
                ->get(['generated_outputs.id', 'generated_outputs.type',
                       'generated_outputs.content', 'generated_outputs.metadata',
                       'generated_outputs.created_at']);

            foreach ($outputs as $o) {
                $assets[] = [
                    'type'       => $o->type,
                    'content'    => $o->content,
                    'metadata'   => $o->metadata,
                    'created_at' => $o->created_at?->toIso8601String(),
                ];
            }

        } catch (\Throwable $e) {
            Log::warning('CampaignContextService: getBestPerformingAssets failed', [
                'campaign_id' => $campaignId,
                'error'       => $e->getMessage(),
            ]);
        }

        return $assets;
    }

    /**
     * Invalidate the context cache for a campaign (call after new job completes).
     */
    public function bustCache(string $campaignId): void
    {
        Cache::forget("campaign:context:{$campaignId}");
    }
}
