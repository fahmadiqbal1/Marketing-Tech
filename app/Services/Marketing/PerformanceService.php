<?php

namespace App\Services\Marketing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tracks and evaluates marketing performance per agent task.
 *
 * All methods have safe fallbacks — returns empty/default data if the
 * marketing_performance table is not yet migrated.
 */
class PerformanceService
{
    /** Organic is considered underperforming if clicks are below this threshold */
    private const UNDERPERFORM_CLICKS_THRESHOLD = 100;

    /**
     * Log a performance entry for a task.
     *
     * @param int    $taskId
     * @param string $campaignType  organic|paid_search|paid_social|email|content|paid_ads_planned
     * @param array  $metrics       impressions, clicks, conversions, cost_usd, revenue_estimate
     */
    public function log(int $taskId, string $campaignType, array $metrics): void
    {
        try {
            $costUsd        = (float) ($metrics['cost_usd']         ?? 0);
            $revenueEst     = (float) ($metrics['revenue_estimate'] ?? 0);
            $roi            = $costUsd > 0
                ? round(($revenueEst - $costUsd) / $costUsd * 100, 4)
                : 0.0;

            DB::table('marketing_performance')->insert([
                'agent_task_id'    => $taskId,
                'campaign_type'    => $campaignType,
                'impressions'      => (int) ($metrics['impressions']  ?? 0),
                'clicks'           => (int) ($metrics['clicks']       ?? 0),
                'conversions'      => (int) ($metrics['conversions']  ?? 0),
                'cost_usd'         => $costUsd,
                'revenue_estimate' => $revenueEst,
                'roi'              => $roi,
                'created_at'       => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("[PerformanceService] Failed to log metrics for task {$taskId}: " . $e->getMessage());
        }
    }

    /**
     * Retrieve all performance records for a task.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getMetrics(int $taskId): \Illuminate\Support\Collection
    {
        try {
            return DB::table('marketing_performance')
                ->where('agent_task_id', $taskId)
                ->orderBy('created_at')
                ->get();
        } catch (\Throwable $e) {
            Log::warning("[PerformanceService] Failed to load metrics for task {$taskId}: " . $e->getMessage());
            return collect();
        }
    }

    /**
     * Return the top-performing entries by CTR and by conversion count.
     * Used by the Iteration Rule — feeds back into content generation prompts.
     *
     * @return array{top_ctr: object|null, top_conversions: object|null, summary: string}
     */
    public function getTopPerformers(int $taskId, int $limit = 3): array
    {
        try {
            $rows = DB::table('marketing_performance')
                ->where('agent_task_id', $taskId)
                ->where('impressions', '>', 0)
                ->get();

            if ($rows->isEmpty()) {
                return ['top_ctr' => null, 'top_conversions' => null, 'summary' => ''];
            }

            // Compute CTR for each row
            $withCtr = $rows->map(function ($row) {
                $row->ctr = $row->impressions > 0
                    ? round($row->clicks / $row->impressions * 100, 4)
                    : 0;
                return $row;
            });

            $topCtr         = $withCtr->sortByDesc('ctr')->take($limit)->values();
            $topConversions = $withCtr->sortByDesc('conversions')->take($limit)->values();

            $summary = "Top by CTR: " . $topCtr->map(fn($r) => "{$r->campaign_type} ({$r->ctr}%)")->implode(', ')
                . ". Top by conversions: " . $topConversions->map(fn($r) => "{$r->campaign_type} ({$r->conversions})")->implode(', ');

            return [
                'top_ctr'         => $topCtr->first(),
                'top_conversions' => $topConversions->first(),
                'all_top_ctr'     => $topCtr,
                'summary'         => $summary,
            ];
        } catch (\Throwable $e) {
            Log::warning("[PerformanceService] getTopPerformers failed for task {$taskId}: " . $e->getMessage());
            return ['top_ctr' => null, 'top_conversions' => null, 'summary' => ''];
        }
    }

    /**
     * Check whether organic performance is underperforming.
     * Returns true when paid ads should be considered.
     */
    public function organicIsUnderperforming(int $taskId): bool
    {
        try {
            $organic = DB::table('marketing_performance')
                ->where('agent_task_id', $taskId)
                ->whereIn('campaign_type', ['organic', 'content', 'email'])
                ->selectRaw('SUM(clicks) as total_clicks, SUM(conversions) as total_conversions')
                ->first();

            if (! $organic || $organic->total_clicks === null) {
                // No organic data yet — organic hasn't been tried, so don't allow ads
                return false;
            }

            return $organic->total_clicks < self::UNDERPERFORM_CLICKS_THRESHOLD
                || $organic->total_conversions === 0;
        } catch (\Throwable $e) {
            Log::warning("[PerformanceService] organicIsUnderperforming check failed: " . $e->getMessage());
            return false;
        }
    }

    public function computeRoi(float $revenue, float $cost): float
    {
        if ($cost <= 0) return 0.0;
        return round(($revenue - $cost) / $cost * 100, 4);
    }
}
