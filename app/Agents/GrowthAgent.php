<?php

namespace App\Agents;

use App\Models\AgentJob;
use App\Models\Experiment;
use App\Models\Campaign;
use App\Services\AI\AnthropicService;
use App\Services\AI\GeminiService;
use App\Services\AI\AIRouter;
use App\Services\AI\OpenAIService;
use App\Services\AI\SwarmOrchestratorService;
use App\Services\ApiCredentialService;
use App\Services\CampaignContextService;
use App\Services\Growth\ExperimentationEngine;
use App\Services\IterationEngineService;
use App\Services\Knowledge\VectorStoreService;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Facades\Log;

class GrowthAgent extends BaseAgent
{
    protected string $agentType = 'growth';

    public function __construct(
        OpenAIService          $openai,
        AnthropicService       $anthropic,
        GeminiService          $gemini,
        TelegramBotService     $telegram,
        VectorStoreService     $knowledge,
        ApiCredentialService   $credentials,
        IterationEngineService $iterationEngine,
        CampaignContextService $campaignContext,
        private readonly ExperimentationEngine $experiments,
        AIRouter $aiRouter,
        SwarmOrchestratorService $swarm,
    ) {
        parent::__construct($openai, $anthropic, $gemini, $telegram, $knowledge, $credentials, $iterationEngine, $campaignContext, $aiRouter, $swarm);
    }

    protected function executeTool(string $name, array $args, AgentJob $job): mixed
    {
        return match ($name) {
            'create_experiment'      => $this->toolCreateExperiment($args),
            'start_experiment'       => $this->toolStartExperiment($args),
            'get_experiment_results' => $this->toolGetResults($args),
            'conclude_experiment'    => $this->toolConclude($args),
            'calculate_significance' => $this->toolCalcSignificance($args),
            'list_experiments'       => $this->toolListExperiments($args),
            'get_metrics'            => $this->toolGetMetrics($args),
            'generate_report'        => $this->toolGenerateReport($args, $job),
            'next_hypothesis'        => $this->toolNextHypothesis($args),
            'create_campaign_ab'     => $this->toolCreateCampaignAB($args),
            default                  => $this->toolResult(false, null, "Unknown tool: {$name}"),
        };
    }

    protected function getToolDefinitions(): array
    {
        return [
            ['type' => 'function', 'function' => [
                'name'        => 'create_experiment',
                'description' => 'Create and start a new A/B or multivariate growth experiment',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'hypothesis' => ['type' => 'string', 'description' => 'What you are testing and why'],
                    'category'   => ['type' => 'string', 'enum' => ['campaign', 'content', 'hiring', 'growth', 'product']],
                    'type'       => ['type' => 'string', 'enum' => ['ab_test', 'multivariate']],
                    'min_sample' => ['type' => 'integer', 'description' => 'Minimum sample size before concluding'],
                ], 'required' => ['hypothesis']],
            ]],
            ['type' => 'function', 'function' => [
                'name'        => 'start_experiment',
                'description' => 'Start a draft experiment',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'experiment_id' => ['type' => 'string'],
                ], 'required' => ['experiment_id']],
            ]],
            ['type' => 'function', 'function' => [
                'name'        => 'get_experiment_results',
                'description' => 'Get current results and statistical analysis for a running experiment',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'experiment_id' => ['type' => 'string'],
                ], 'required' => ['experiment_id']],
            ]],
            ['type' => 'function', 'function' => [
                'name'        => 'conclude_experiment',
                'description' => 'Trigger final analysis and conclusion of a completed experiment',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'experiment_id' => ['type' => 'string'],
                ], 'required' => ['experiment_id']],
            ]],
            ['type' => 'function', 'function' => [
                'name'        => 'calculate_significance',
                'description' => 'Calculate statistical significance given raw conversion numbers',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'control_visitors'    => ['type' => 'integer'],
                    'control_conversions' => ['type' => 'integer'],
                    'variant_visitors'    => ['type' => 'integer'],
                    'variant_conversions' => ['type' => 'integer'],
                    'confidence'          => ['type' => 'number', 'description' => 'e.g. 95 for 95%'],
                ], 'required' => ['control_visitors', 'control_conversions', 'variant_visitors', 'variant_conversions']],
            ]],
            ['type' => 'function', 'function' => [
                'name'        => 'list_experiments',
                'description' => 'List experiments filtered by status and category',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'status'   => ['type' => 'string', 'enum' => ['draft', 'running', 'paused', 'concluded', 'all']],
                    'category' => ['type' => 'string'],
                    'limit'    => ['type' => 'integer'],
                ]],
            ]],
            ['type' => 'function', 'function' => [
                'name'        => 'get_metrics',
                'description' => 'Get platform metrics for a date range from the database',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'metric'      => ['type' => 'string', 'enum' => ['campaigns_sent', 'campaigns_opens', 'experiments_run', 'candidates_added', 'content_created', 'ai_cost_usd']],
                    'date_from'   => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'date_to'     => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                ], 'required' => ['metric']],
            ]],
            ['type' => 'function', 'function' => [
                'name'        => 'generate_report',
                'description' => 'Generate a growth performance summary report',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'period' => ['type' => 'string', 'enum' => ['weekly', 'monthly', 'quarterly']],
                ], 'required' => ['period']],
            ]],
            ['type' => 'function', 'function' => [
                'name'        => 'next_hypothesis',
                'description' => 'Use past experiment learnings to generate the next test hypothesis',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'category' => ['type' => 'string'],
                ]],
            ]],
            ['type' => 'function', 'function' => [
                'name'        => 'create_campaign_ab',
                'description' => 'Auto-generate an A/B experiment for an existing campaign',
                'parameters'  => ['type' => 'object', 'properties' => [
                    'campaign_id' => ['type' => 'string'],
                ], 'required' => ['campaign_id']],
            ]],
        ];
    }

    // ── Tool implementations ──────────────────────────────────

    private function toolCreateExperiment(array $args): string
    {
        try {
            $experiment = $this->experiments->generateFromHypothesis(
                $args['hypothesis'],
                $args['category'] ?? 'growth'
            );
            $this->experiments->start($experiment);
            return $this->toolResult(true, [
                'experiment_id' => $experiment->id,
                'name'          => $experiment->name,
                'status'        => $experiment->status,
                'variants'      => count($experiment->variants ?? []),
                'min_sample'    => $experiment->min_sample_size,
            ]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolStartExperiment(array $args): string
    {
        try {
            $experiment = Experiment::findOrFail($args['experiment_id']);
            $this->experiments->start($experiment);
            return $this->toolResult(true, ['status' => 'running', 'id' => $experiment->id]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolGetResults(array $args): string
    {
        try {
            $experiment = Experiment::findOrFail($args['experiment_id']);
            $results    = $this->experiments->analyze($experiment);
            return $this->toolResult(true, array_merge(
                ['id' => $experiment->id, 'name' => $experiment->name, 'status' => $experiment->status],
                $results
            ));
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolConclude(array $args): string
    {
        try {
            $experiment = Experiment::findOrFail($args['experiment_id']);
            $results    = $this->experiments->analyze($experiment);
            $fresh      = $experiment->fresh();
            return $this->toolResult(true, [
                'id'             => $fresh->id,
                'status'         => $fresh->status,
                'winner'         => $fresh->winning_variant,
                'conclusion'     => $fresh->conclusion,
                'learnings'      => $fresh->learnings,
                'results'        => $results,
            ]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolCalcSignificance(array $args): string
    {
        $cv = (int) $args['control_visitors'];
        $cc = (int) $args['control_conversions'];
        $vv = (int) $args['variant_visitors'];
        $vc = (int) $args['variant_conversions'];

        if ($cv < 1 || $vv < 1) {
            return $this->toolResult(false, null, 'Need at least 1 visitor per variant');
        }

        $p1   = $cc / $cv;
        $p2   = $vc / $vv;
        $pool = ($cc + $vc) / ($cv + $vv);
        $se   = sqrt($pool * (1 - $pool) * (1 / $cv + 1 / $vv));
        $z    = $se > 0 ? ($p2 - $p1) / $se : 0;
        $lift = $p1 > 0 ? round(($p2 - $p1) / $p1 * 100, 2) : 0;

        // Approximate p-value from z-score
        $absZ  = abs($z);
        $pVal  = 2 * (1 - $this->normCdf($absZ));
        $alpha = 1 - (((float) ($args['confidence'] ?? 95)) / 100);

        return $this->toolResult(true, [
            'z_score'         => round($z, 4),
            'p_value'         => round($pVal, 6),
            'lift_pct'        => $lift,
            'control_rate'    => round($p1 * 100, 4) . '%',
            'variant_rate'    => round($p2 * 100, 4) . '%',
            'significant'     => $pVal < $alpha,
            'confidence_used' => ($args['confidence'] ?? 95) . '%',
            'interpretation'  => $pVal < $alpha
                ? ($lift > 0 ? "Variant wins — {$lift}% lift (p={$pVal})" : "Control wins (variant hurt)")
                : "Not significant yet — collect more data (p={$pVal})",
        ]);
    }

    private function toolListExperiments(array $args): string
    {
        $status = $args['status'] ?? 'all';
        $query  = Experiment::query();
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if (! empty($args['category'])) {
            $query->where('category', $args['category']);
        }
        $rows = $query->orderByDesc('created_at')
            ->limit($args['limit'] ?? 10)
            ->get(['id', 'name', 'status', 'category', 'type', 'current_sample_size', 'min_sample_size', 'winning_variant', 'concluded_at'])
            ->toArray();
        return $this->toolResult(true, $rows);
    }

    private function toolGetMetrics(array $args): string
    {
        $from = $args['date_from'] ?? now()->subDays(30)->toDateString();
        $to   = $args['date_to']   ?? now()->toDateString();

        $value = match ($args['metric']) {
            'campaigns_sent'    => \App\Models\Campaign::whereBetween('sent_at', [$from, $to])->count(),
            'campaigns_opens'   => \App\Models\Campaign::whereBetween('sent_at', [$from, $to])->sum('open_count'),
            'experiments_run'   => Experiment::whereBetween('started_at', [$from, $to])->count(),
            'candidates_added'  => \App\Models\Candidate::whereBetween('created_at', [$from, $to])->count(),
            'content_created'   => \App\Models\ContentItem::whereBetween('created_at', [$from, $to])->count(),
            'ai_cost_usd'       => \App\Models\AiRequest::whereBetween('requested_at', [$from, $to])->sum('cost_usd'),
            default             => null,
        };

        return $this->toolResult(true, [
            'metric' => $args['metric'],
            'value'  => $value,
            'from'   => $from,
            'to'     => $to,
        ]);
    }

    private function toolGenerateReport(array $args, AgentJob $job): string
    {
        $period = $args['period'] ?? 'monthly';
        $from   = match ($period) {
            'weekly'    => now()->subWeek()->toDateString(),
            'quarterly' => now()->subMonths(3)->toDateString(),
            default     => now()->subMonth()->toDateString(),
        };

        $metrics = [
            'campaigns_sent'        => \App\Models\Campaign::where('sent_at', '>=', $from)->count(),
            'total_opens'           => \App\Models\Campaign::where('sent_at', '>=', $from)->sum('open_count'),
            'experiments_started'   => Experiment::where('started_at', '>=', $from)->count(),
            'experiments_concluded' => Experiment::where('status', 'concluded')->where('concluded_at', '>=', $from)->count(),
            'candidates_processed'  => \App\Models\Candidate::where('created_at', '>=', $from)->count(),
            'content_items_created' => \App\Models\ContentItem::where('created_at', '>=', $from)->count(),
            'ai_spend_usd'          => round(\App\Models\AiRequest::where('requested_at', '>=', $from)->sum('cost_usd'), 2),
        ];

        $prompt = "Write a clear, data-driven {$period} growth operations report (3 paragraphs) based on:\n" . json_encode($metrics, JSON_PRETTY_PRINT);

        try {
            $narrative = $this->anthropic->complete($prompt, maxTokens: 600, temperature: 0.5);
        } catch (\Throwable) {
            $narrative = "Report generated from {$from} to " . now()->toDateString() . ".";
        }

        return $this->toolResult(true, [
            'period'    => $period,
            'from'      => $from,
            'to'        => now()->toDateString(),
            'metrics'   => $metrics,
            'narrative' => $narrative,
        ]);
    }

    private function toolNextHypothesis(array $args): string
    {
        try {
            $result = $this->experiments->generateNextVariant($args['category'] ?? 'growth');
            return $this->toolResult(true, $result);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolCreateCampaignAB(array $args): string
    {
        try {
            $campaign   = Campaign::findOrFail($args['campaign_id']);
            $experiment = $this->experiments->generateForCampaign($campaign);
            $this->experiments->start($experiment);
            return $this->toolResult(true, [
                'experiment_id' => $experiment->id,
                'campaign_id'   => $campaign->id,
                'variants'      => count($experiment->variants ?? []),
                'status'        => 'running',
            ]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    // ── Math helper ───────────────────────────────────────────

    private function normCdf(float $x): float
    {
        // Hart approximation of normal CDF — accurate to 6 decimal places
        if ($x < 0) return 1 - $this->normCdf(-$x);
        $t = 1 / (1 + 0.2316419 * $x);
        $poly = ((((1.330274429 * $t - 1.821255978) * $t + 1.781477937) * $t - 0.356563782) * $t + 0.319381530) * $t;
        return 1 - $poly * (1 / sqrt(2 * M_PI)) * exp(-0.5 * $x * $x);
    }
}
