<?php

namespace App\Services;

use App\Models\ContentPerformance;
use App\Models\ContentVariation;
use App\Models\GeneratedOutput;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * IterationEngineService — the feedback loop that makes agents learn.
 *
 * Before each agent run, this service queries past ContentVariation performance
 * and returns a formatted "winning patterns" string for injection into the agent
 * system prompt. This causes the agent to replicate high-performing structures
 * and avoid low-performing ones.
 *
 * It also handles storing new variations and recording external performance data.
 */
class IterationEngineService
{
    private const CACHE_TTL    = 300;  // 5 minutes
    private const MIN_SCORE    = 0.0;
    private const TOP_N        = 5;

    // ─── Prompt Context ───────────────────────────────────────────────

    /**
     * Build a "winning patterns" string for a given agent type.
     * Returns empty string if no performance data exists yet.
     */
    public function getPromptContext(string $agentType): string
    {
        $cacheKey = "iteration:prompt:{$agentType}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($agentType) {
            try {
                return $this->buildPromptContext($agentType);
            } catch (\Throwable $e) {
                Log::warning('IterationEngineService: failed to build prompt context', [
                    'agent_type' => $agentType,
                    'error'      => $e->getMessage(),
                ]);
                return '';
            }
        });
    }

    private function buildPromptContext(string $agentType): string
    {
        // Get top-scoring variations across all jobs of this agent type
        $topVariations = ContentVariation::query()
            ->join('agent_jobs', 'content_variations.agent_job_id', '=', 'agent_jobs.id')
            ->join('content_performance', 'content_performance.content_variation_id', '=', 'content_variations.id')
            ->where('agent_jobs.agent_type', $agentType)
            ->where('content_performance.score', '>', self::MIN_SCORE)
            ->orderByDesc('content_performance.score')
            ->limit(self::TOP_N)
            ->get([
                'content_variations.variation_label',
                'content_variations.metadata',
                'content_performance.score',
                'content_performance.clicks',
                'content_performance.conversions',
                'content_performance.ctr',
            ]);

        if ($topVariations->isEmpty()) {
            return '';
        }

        $hooks      = [];
        $tones      = [];
        $structures = [];
        $examples   = [];

        foreach ($topVariations as $v) {
            $meta = is_array($v->metadata) ? $v->metadata : (json_decode($v->metadata ?? '{}', true) ?? []);

            if (! empty($meta['hook_type']))  $hooks[]      = $meta['hook_type'];
            if (! empty($meta['tone']))       $tones[]      = $meta['tone'];
            if (! empty($meta['structure']))  $structures[] = $meta['structure'];

            $examples[] = sprintf(
                '  - Label %s: score=%.1f, CTR=%.2f%%, conversions=%d | hook=%s, tone=%s',
                $v->variation_label,
                $v->score,
                $v->ctr * 100,
                $v->conversions,
                $meta['hook_type']  ?? '?',
                $meta['tone']       ?? '?',
            );
        }

        $lines = ['[HIGH-PERFORMING PATTERNS FROM PAST RUNS — reuse these]'];
        if ($hooks)      $lines[] = 'Top hooks: '      . implode(', ', array_unique($hooks));
        if ($tones)      $lines[] = 'Top tones: '      . implode(', ', array_unique($tones));
        if ($structures) $lines[] = 'Top structures: ' . implode(', ', array_unique($structures));
        if ($examples)   $lines[] = 'Examples:' . "\n" . implode("\n", $examples);

        return implode("\n", $lines);
    }

    // ─── Variation Storage ────────────────────────────────────────────

    /**
     * Store a single content variation for a job.
     */
    public function storeVariation(
        string $agentJobId,
        string $label,
        string $content,
        array  $metadata = [],
    ): ContentVariation {
        return ContentVariation::create([
            'agent_job_id'    => $agentJobId,
            'variation_label' => strtoupper($label),
            'content'         => $content,
            'metadata'        => $metadata,
            'is_winner'       => false,
            'created_at'      => now(),
        ]);
    }

    /**
     * Store the primary output for a completed job.
     */
    public function storeOutput(
        string $agentJobId,
        string $content,
        string $type     = 'content',
        array  $metadata = [],
    ): GeneratedOutput {
        // Determine version number (increment if job has prior outputs of same type)
        $version = GeneratedOutput::where('agent_job_id', $agentJobId)
                ->where('type', $type)
                ->max('version') + 1;

        return GeneratedOutput::create([
            'agent_job_id' => $agentJobId,
            'type'         => $type,
            'content'      => $content,
            'version'      => $version,
            'is_winner'    => false,
            'metadata'     => $metadata,
            'created_at'   => now(),
        ]);
    }

    // ─── Performance Recording ────────────────────────────────────────

    /**
     * Record performance metrics for a content variation.
     * Automatically computes CTR and score.
     */
    public function recordPerformance(
        string $variationId,
        int    $impressions,
        int    $clicks,
        int    $conversions,
        string $source = 'manual',
    ): ContentPerformance {
        $ctr   = $impressions > 0 ? round($clicks / $impressions, 4) : 0.0;
        $score = ContentPerformance::computeScore($impressions, $clicks, $conversions);

        $perf = ContentPerformance::create([
            'content_variation_id' => $variationId,
            'impressions'          => $impressions,
            'clicks'               => $clicks,
            'conversions'          => $conversions,
            'ctr'                  => $ctr,
            'score'                => $score,
            'source'               => $source,
            'recorded_at'          => now(),
        ]);

        // Auto-mark winner if this variation now leads its siblings
        $this->updateWinnerForJob($variationId);

        // Bust cache so next agent run gets fresh patterns
        $this->bustPromptCache();

        return $perf;
    }

    /**
     * Mark the highest-scoring variation for a job as winner.
     * Clears winner flag on all siblings first.
     */
    private function updateWinnerForJob(string $variationId): void
    {
        try {
            $variation = ContentVariation::find($variationId);
            if (! $variation) return;

            // Get all variations for the same job
            $siblings = ContentVariation::where('agent_job_id', $variation->agent_job_id)->get();
            if ($siblings->count() < 2) return;

            $scored = $siblings->map(fn($v) => [
                'id'    => $v->id,
                'score' => (float) ContentPerformance::where('content_variation_id', $v->id)
                    ->max('score') ?? 0.0,
            ])->sortByDesc('score');

            $winnerId = $scored->first()['id'] ?? null;
            if (! $winnerId) return;

            ContentVariation::where('agent_job_id', $variation->agent_job_id)
                ->update(['is_winner' => false]);
            ContentVariation::where('id', $winnerId)->update(['is_winner' => true]);

        } catch (\Throwable $e) {
            Log::warning('IterationEngineService: winner update failed', ['error' => $e->getMessage()]);
        }
    }

    // ─── Analysis Helpers ─────────────────────────────────────────────

    /**
     * Return top hook_type values from recent high-scoring variations.
     */
    public function getTopHooks(string $agentType, int $limit = 3): array
    {
        return $this->getTopMetadataField($agentType, 'hook_type', $limit);
    }

    /**
     * Return top structure values from recent high-scoring variations.
     */
    public function getTopFormats(string $agentType, int $limit = 3): array
    {
        return $this->getTopMetadataField($agentType, 'structure', $limit);
    }

    private function getTopMetadataField(string $agentType, string $field, int $limit): array
    {
        try {
            $variations = ContentVariation::query()
                ->join('agent_jobs', 'content_variations.agent_job_id', '=', 'agent_jobs.id')
                ->join('content_performance', 'content_performance.content_variation_id', '=', 'content_variations.id')
                ->where('agent_jobs.agent_type', $agentType)
                ->orderByDesc('content_performance.score')
                ->limit(20)
                ->pluck('content_variations.metadata');

            $counts = [];
            foreach ($variations as $raw) {
                $meta = is_array($raw) ? $raw : (json_decode($raw ?? '{}', true) ?? []);
                $val  = $meta[$field] ?? null;
                if ($val) $counts[$val] = ($counts[$val] ?? 0) + 1;
            }

            arsort($counts);
            return array_slice(array_keys($counts), 0, $limit);

        } catch (\Throwable) {
            return [];
        }
    }

    private function bustPromptCache(): void
    {
        foreach (array_keys(config('agents.agents', [])) as $agentType) {
            Cache::forget("iteration:prompt:{$agentType}");
        }
    }
}
