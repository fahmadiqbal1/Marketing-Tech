<?php

namespace App\Services;

use App\Services\AI\AIRouter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns raw outcome data into higher-order, human-readable strategic insights.
 * Runs daily via ExtractStrategicInsights job.
 * Insights are stored in strategic_insights and available to StrategicAgent via context.
 */
class InsightExtractionService
{
    public function __construct(private readonly AIRouter $router) {}

    /**
     * Extract and store insights from the last N days of outcome data.
     */
    public function extract(int $days = 30): int
    {
        $outcomes = DB::table('agent_outcomes')
            ->where('created_at', '>=', now()->subDays($days))
            ->get()
            ->groupBy('domain');

        $inserted = 0;

        foreach ($outcomes as $domain => $rows) {
            if ($rows->count() < 5) {
                continue; // not enough data
            }

            $stats = $this->aggregateStats($rows);
            $insight = $this->synthesiseInsight($domain, $stats);

            if ($insight) {
                DB::table('strategic_insights')->insert([
                    'id'             => \Illuminate\Support\Str::uuid(),
                    'domain'         => $domain,
                    'metric'         => 'composite',
                    'insight'        => $insight,
                    'confidence'     => min(1.0, $rows->count() / 50),
                    'sample_size'    => $rows->count(),
                    'supporting_data'=> json_encode($stats),
                    'extracted_at'   => now(),
                    'expires_at'     => now()->addDays(60),
                ]);
                $inserted++;
            }
        }

        // Also prune expired insights
        DB::table('strategic_insights')
            ->where('expires_at', '<', now())
            ->delete();

        Log::info('InsightExtractionService: extracted insights', ['count' => $inserted]);

        return $inserted;
    }

    /**
     * Retrieve active insights for a domain (used by StrategicAgent context builder).
     */
    public function getInsights(string $domain, int $limit = 5): array
    {
        return DB::table('strategic_insights')
            ->where('domain', $domain)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('confidence')
            ->limit($limit)
            ->pluck('insight')
            ->toArray();
    }

    /**
     * Retrieve all active insights (for dashboard + StrategicAgent global context).
     */
    public function getAllInsights(int $limit = 20): array
    {
        return DB::table('strategic_insights')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('confidence')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'domain'      => $r->domain,
                'insight'     => $r->insight,
                'confidence'  => $r->confidence,
                'sample_size' => $r->sample_size,
                'extracted_at'=> $r->extracted_at,
            ])
            ->toArray();
    }

    private function aggregateStats(\Illuminate\Support\Collection $rows): array
    {
        $byMetric = $rows->groupBy('metric');
        $stats    = [];

        foreach ($byMetric as $metric => $metricRows) {
            $values = $metricRows->pluck('value');
            $stats[$metric] = [
                'count'  => $values->count(),
                'avg'    => round($values->avg(), 4),
                'max'    => round($values->max(), 4),
                'min'    => round($values->min(), 4),
                'trend'  => $this->calculateTrend($metricRows),
            ];
        }

        return $stats;
    }

    private function calculateTrend(\Illuminate\Support\Collection $rows): string
    {
        $sorted = $rows->sortBy('created_at')->values();
        if ($sorted->count() < 4) {
            return 'insufficient_data';
        }

        $firstHalf  = $sorted->take(intval($sorted->count() / 2))->avg('value');
        $secondHalf = $sorted->skip(intval($sorted->count() / 2))->avg('value');
        $delta      = $secondHalf - $firstHalf;

        return match (true) {
            $delta > 0.1  => 'improving',
            $delta < -0.1 => 'declining',
            default       => 'stable',
        };
    }

    private function synthesiseInsight(string $domain, array $stats): ?string
    {
        if (empty($stats)) {
            return null;
        }

        $statsJson = json_encode($stats, JSON_PRETTY_PRINT);

        try {
            return $this->router->complete(
                prompt: "You are a business intelligence analyst. Given these outcome statistics for the '{$domain}' domain, write ONE concise, actionable insight (max 2 sentences). Be specific about numbers. Stats:\n{$statsJson}",
                model: 'gpt-4o-mini',
                maxTokens: 150,
                temperature: 0.3,
            );
        } catch (\Throwable $e) {
            Log::warning('InsightExtractionService: LLM call failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
