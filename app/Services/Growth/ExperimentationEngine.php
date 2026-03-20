<?php

namespace App\Services\Growth;

use App\Models\Campaign;
use App\Models\Experiment;
use App\Models\ExperimentEvent;
use App\Services\AI\AIRouter;
use App\Services\Knowledge\ContextGraphService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ExperimentationEngine
{
    public function __construct(
        private readonly AIRouter            $aiRouter,
        private readonly ContextGraphService $contextGraph,
    ) {}

    // ─── ExperimentGenerator ─────────────────────────────────────

    /**
     * Auto-generate an A/B experiment for a campaign.
     */
    public function generateForCampaign(Campaign $campaign): Experiment
    {
        $prompt = <<<PROMPT
You are a growth strategist. Generate an A/B test experiment for this email campaign.

Campaign: {$campaign->name}
Type: {$campaign->type}
Current subject: {$campaign->subject}
Target audience: {$campaign->audience}

Generate 2 variants (A and B) with different subject lines or CTAs.
Return ONLY valid JSON:
{
  "hypothesis": "Changing X will improve Y because Z",
  "metric_primary": "open_rate",
  "variants": [
    {"name": "A", "description": "Original", "config": {"subject": "...", "cta": "..."}, "traffic_pct": 50},
    {"name": "B", "description": "Variant",  "config": {"subject": "...", "cta": "..."}, "traffic_pct": 50}
  ],
  "min_sample_size": 200,
  "confidence_level": 95
}
PROMPT;

        $raw  = $this->aiRouter->complete($prompt, 'gpt-4o', 800, 0.7);
        $clean = preg_replace('/^```json\s*|\s*```$/m', '', trim($raw));
        $data  = json_decode($clean, true);

        if (! $data) {
            throw new \RuntimeException("Failed to generate experiment plan");
        }

        $experiment = Experiment::create([
            'id'                => (string) Str::uuid(),
            'name'              => "A/B Test: {$campaign->name}",
            'type'              => 'ab_test',
            'category'          => 'campaign',
            'status'            => 'draft',
            'hypothesis'        => $data['hypothesis'],
            'metric_primary'    => $data['metric_primary'] ?? 'open_rate',
            'variants'          => $data['variants'],
            'min_sample_size'   => $data['min_sample_size'] ?? 200,
            'confidence_level'  => $data['confidence_level'] ?? 95.0,
            'auto_generated'    => true,
            'parent_campaign_id' => $campaign->id,
        ]);

        Log::info("Experiment generated", ['id' => $experiment->id, 'campaign' => $campaign->id]);
        return $experiment;
    }

    /**
     * Generate a standalone growth experiment from a hypothesis.
     */
    public function generateFromHypothesis(string $hypothesis, string $category = 'growth'): Experiment
    {
        $prompt = <<<PROMPT
Design a growth experiment from this hypothesis.
Hypothesis: {$hypothesis}

Return ONLY valid JSON:
{
  "name": "Short experiment name",
  "type": "ab_test",
  "metric_primary": "conversion_rate",
  "metrics_secondary": ["revenue", "retention"],
  "variants": [
    {"name":"Control","description":"Current state","config":{},"traffic_pct":50},
    {"name":"Treatment","description":"The change","config":{},"traffic_pct":50}
  ],
  "min_sample_size": 500,
  "confidence_level": 95
}
PROMPT;

        $raw   = $this->aiRouter->complete($prompt, 'gpt-4o', 800, 0.7);
        $clean = preg_replace('/^```json\s*|\s*```$/m', '', trim($raw));
        $data  = json_decode($clean, true) ?? [];

        return Experiment::create([
            'id'               => (string) Str::uuid(),
            'name'             => $data['name']             ?? "Experiment: " . Str::limit($hypothesis, 50),
            'type'             => $data['type']             ?? 'ab_test',
            'category'         => $category,
            'status'           => 'draft',
            'hypothesis'       => $hypothesis,
            'metric_primary'   => $data['metric_primary']   ?? 'conversion_rate',
            'metrics_secondary' => $data['metrics_secondary'] ?? [],
            'variants'         => $data['variants']         ?? [],
            'min_sample_size'  => $data['min_sample_size']  ?? 500,
            'confidence_level' => $data['confidence_level'] ?? 95.0,
            'auto_generated'   => true,
        ]);
    }

    // ─── ExperimentScheduler ──────────────────────────────────────

    public function start(Experiment $experiment): void
    {
        if ($experiment->status !== 'draft') {
            throw new \RuntimeException("Experiment must be in draft status to start");
        }

        $experiment->update([
            'status'     => 'running',
            'started_at' => now(),
        ]);

        Log::info("Experiment started", ['id' => $experiment->id]);
    }

    public function pause(Experiment $experiment): void
    {
        $experiment->update(['status' => 'paused']);
    }

    // ─── PerformanceAnalyzer ──────────────────────────────────────

    /**
     * Record an experiment event (impression, click, conversion).
     */
    public function recordEvent(
        string $experimentId,
        string $variant,
        string $eventType,
        float  $value    = 0,
        array  $metadata = [],
    ): void {
        ExperimentEvent::create([
            'experiment_id' => $experimentId,
            'variant'       => $variant,
            'event_type'    => $eventType,
            'value'         => $value,
            'metadata'      => $metadata,
        ]);

        // Update sample size
        Experiment::where('id', $experimentId)->increment('current_sample_size');
    }

    /**
     * Analyze experiment results and calculate statistical significance.
     */
    public function analyze(Experiment $experiment): array
    {
        $variants = $experiment->variants;
        if (empty($variants)) {
            return ['error' => 'No variants defined'];
        }

        $results = [];

        foreach ($variants as $variant) {
            $name = $variant['name'];

            $impressions  = ExperimentEvent::where('experiment_id', $experiment->id)
                ->where('variant', $name)
                ->where('event_type', 'impression')
                ->count();

            $conversions  = ExperimentEvent::where('experiment_id', $experiment->id)
                ->where('variant', $name)
                ->where('event_type', 'conversion')
                ->count();

            $revenue      = ExperimentEvent::where('experiment_id', $experiment->id)
                ->where('variant', $name)
                ->where('event_type', 'conversion')
                ->sum('value');

            $results[$name] = [
                'impressions'     => $impressions,
                'conversions'     => $conversions,
                'conversion_rate' => $impressions > 0 ? round($conversions / $impressions * 100, 4) : 0,
                'revenue'         => round($revenue, 2),
                'arpu'            => $impressions > 0 ? round($revenue / $impressions, 4) : 0,
            ];
        }

        // Calculate statistical significance (two-proportion z-test)
        $significance = $this->calculateSignificance($results, $variants);

        $experiment->update(['results' => $results]);

        if ($significance['significant'] && $experiment->current_sample_size >= $experiment->min_sample_size) {
            $this->conclude($experiment, $results, $significance);
        }

        return array_merge($results, ['significance' => $significance]);
    }

    // ─── StrategyOptimizer ────────────────────────────────────────

    /**
     * Conclude an experiment and extract learnings.
     */
    public function conclude(Experiment $experiment, array $results, array $significance): void
    {
        $winner     = $this->determineWinner($results);
        $conclusion = $this->generateConclusion($experiment, $results, $significance, $winner);
        $learnings  = $this->extractLearnings($experiment, $results, $winner);

        $experiment->update([
            'status'                => 'concluded',
            'winning_variant'       => $winner,
            'achieved_significance' => $significance['p_value'] ?? null,
            'conclusion'            => $conclusion,
            'learnings'             => $learnings,
            'concluded_at'          => now(),
        ]);

        // Store learnings in context graph for future experiments
        $this->contextGraph->createNode(
            type:       'learning',
            title:      "Experiment result: {$experiment->name}",
            content:    $conclusion,
            attributes: [
                'experiment_id'  => $experiment->id,
                'winner'         => $winner,
                'p_value'        => $significance['p_value'] ?? null,
                'results_summary' => $results,
            ],
            tags:       ['experiment', 'learning', $experiment->category],
            category:   $experiment->category,
            importance: 8,
        );

        Log::info("Experiment concluded", ['id' => $experiment->id, 'winner' => $winner]);
    }

    /**
     * Generate optimised variants for the next experiment using past learnings.
     */
    public function generateNextVariant(string $category): array
    {
        $pastLearnings = $this->contextGraph->search(
            "successful experiments {$category} optimization",
            topK: 5,
            category: $category
        );

        $context = collect($pastLearnings)
            ->map(fn($l) => $l['title'] . ': ' . $l['content'])
            ->take(3)
            ->implode("\n\n");

        $prompt = <<<PROMPT
Based on these past experiment learnings, generate the next optimisation hypothesis for {$category}:

{$context}

Return a single hypothesis string (1-2 sentences) describing what to test next and why it should work.
PROMPT;

        $hypothesis = trim($this->aiRouter->complete($prompt, 'gpt-4o', 200, 0.8));

        return [
            'hypothesis' => $hypothesis,
            'category'   => $category,
            'based_on'   => count($pastLearnings) . ' past experiments',
        ];
    }

    // ── Private statistical methods ───────────────────────────────

    private function calculateSignificance(array $results, array $variants): array
    {
        if (count($results) < 2) {
            return ['significant' => false, 'p_value' => null];
        }

        $variantNames = array_column($variants, 'name');
        $control      = $results[$variantNames[0]] ?? null;
        $treatment    = $results[$variantNames[1]] ?? null;

        if (! $control || ! $treatment || $control['impressions'] < 10 || $treatment['impressions'] < 10) {
            return ['significant' => false, 'p_value' => null, 'reason' => 'insufficient_data'];
        }

        $p1 = $control['conversions']  / $control['impressions'];
        $p2 = $treatment['conversions'] / $treatment['impressions'];
        $n1 = $control['impressions'];
        $n2 = $treatment['impressions'];

        // Pooled proportion
        $p  = ($control['conversions'] + $treatment['conversions']) / ($n1 + $n2);

        if ($p <= 0 || $p >= 1) {
            return ['significant' => false, 'p_value' => null, 'reason' => 'no_variation'];
        }

        $se = sqrt($p * (1 - $p) * (1 / $n1 + 1 / $n2));
        if ($se == 0) {
            return ['significant' => false, 'p_value' => null, 'reason' => 'zero_se'];
        }

        $z     = ($p2 - $p1) / $se;
        $pValue = 2 * (1 - $this->normalCdf(abs($z)));

        $threshold  = 1 - (95.0 / 100); // 0.05
        $significant = $pValue < $threshold;

        return [
            'significant'     => $significant,
            'p_value'         => round($pValue, 6),
            'z_score'         => round($z, 4),
            'lift'            => $p1 > 0 ? round(($p2 - $p1) / $p1 * 100, 2) : 0,
            'threshold'       => $threshold,
        ];
    }

    private function normalCdf(float $x): float
    {
        // Approximation of normal CDF
        $t  = 1 / (1 + 0.2316419 * $x);
        $y  = 1 - (((((1.330274429 * $t - 1.821255978) * $t + 1.781477937) * $t - 0.356563782) * $t + 0.319381530) * $t)
              * (1 / sqrt(2 * M_PI)) * exp(-0.5 * $x * $x);
        return $y;
    }

    private function determineWinner(array $results): ?string
    {
        $best     = null;
        $bestRate = -1;

        foreach ($results as $variant => $data) {
            if ($data['conversion_rate'] > $bestRate) {
                $bestRate = $data['conversion_rate'];
                $best     = $variant;
            }
        }

        return $best;
    }

    private function generateConclusion(Experiment $experiment, array $results, array $significance, ?string $winner): string
    {
        $pValue = $significance['p_value'] ?? 'N/A';
        $lift   = $significance['lift']    ?? 0;
        $sig    = $significance['significant'] ? 'statistically significant' : 'not statistically significant';

        return "Experiment '{$experiment->name}' concluded. Winner: {$winner} with {$lift}% lift. "
             . "Result is {$sig} (p={$pValue}). "
             . "Sample size: {$experiment->current_sample_size}/{$experiment->min_sample_size} required.";
    }

    private function extractLearnings(Experiment $experiment, array $results, ?string $winner): array
    {
        $learnings = [];

        if ($winner && ! empty($results[$winner])) {
            $winnerData = $results[$winner];
            $learnings[] = "Variant '{$winner}' achieved {$winnerData['conversion_rate']}% conversion rate";
            $learnings[] = "Hypothesis validated: {$experiment->hypothesis}";
        } else {
            $learnings[] = "No clear winner — hypothesis not validated";
            $learnings[] = "Recommend reformulating hypothesis with stronger differentiation";
        }

        return $learnings;
    }
}
