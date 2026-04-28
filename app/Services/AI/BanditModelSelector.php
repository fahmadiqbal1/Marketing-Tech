<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * UCB1 Multi-Armed Bandit model selector.
 * Automatically discovers the best model per task type through exploration + exploitation.
 * Falls back to config defaults until enough data is collected (< 5 pulls per model).
 */
class BanditModelSelector
{
    /** Minimum pulls before a model enters the bandit pool. */
    private const MIN_PULLS = 3;

    /** Exploration constant — higher = more exploration of unknown models. */
    private const UCB_CONSTANT = 2.0;

    /**
     * Select the best model for a given task type using UCB1.
     * Returns model name string ready for AIRouter.
     */
    public static function choose(string $taskType): string
    {
        if (! config('agents.bandit_model_selection', false)) {
            return self::defaultModel($taskType);
        }

        $models = DB::table('model_performance')
            ->where('task_type', $taskType)
            ->where('pulls', '>=', self::MIN_PULLS)
            ->get();

        if ($models->isEmpty()) {
            return self::defaultModel($taskType);
        }

        $totalPulls = max(1, $models->sum('pulls'));
        $bestModel  = null;
        $bestScore  = -INF;

        foreach ($models as $row) {
            $avgReward  = $row->pulls > 0 ? $row->total_reward / $row->pulls : 0.5;
            $exploration = sqrt((self::UCB_CONSTANT * log($totalPulls)) / max(1, $row->pulls));
            $ucbScore   = $avgReward + $exploration;

            if ($ucbScore > $bestScore) {
                $bestScore = $ucbScore;
                $bestModel = $row->model_name;
            }
        }

        Log::debug('BanditModelSelector', [
            'task_type'    => $taskType,
            'chosen_model' => $bestModel,
            'ucb_score'    => $bestScore,
            'total_pulls'  => $totalPulls,
        ]);

        return $bestModel ?? self::defaultModel($taskType);
    }

    /**
     * Record a pull and its reward after an LLM call completes.
     * Call after outcome is known (quality score from 0.0–1.0).
     */
    public static function recordReward(
        string $modelName,
        string $taskType,
        float  $reward,        // 0.0–1.0 (quality × success - cost - latency penalty)
        float  $latencyMs = 0,
        float  $costUsd = 0,
    ): void {
        DB::table('model_performance')->updateOrInsert(
            ['model_name' => $modelName, 'task_type' => $taskType],
            [
                'pulls'          => DB::raw('pulls + 1'),
                'total_reward'   => DB::raw("total_reward + {$reward}"),
                'avg_latency_ms' => DB::raw("((avg_latency_ms * pulls) + {$latencyMs}) / (pulls + 1)"),
                'avg_cost_usd'   => DB::raw("((avg_cost_usd * pulls) + {$costUsd}) / (pulls + 1)"),
                'last_updated_at' => now(),
            ]
        );
    }

    /**
     * Record a simple completion pull with basic success/failure reward.
     * Used when full outcome metrics are not yet available.
     */
    public static function recordCompletion(
        string $modelName,
        string $taskType,
        bool   $success,
        float  $latencyMs = 0,
        float  $costUsd = 0,
    ): void {
        $reward = $success ? 0.7 : 0.1;
        self::recordReward($modelName, $taskType, $reward, $latencyMs, $costUsd);
    }

    /**
     * Recalculate composite score for all models (run nightly).
     * Score = success_rate×0.5 + quality×0.3 - cost_penalty×0.1 - latency_penalty×0.1
     */
    public static function recalculateScores(): void
    {
        $models = DB::table('model_performance')->get();

        foreach ($models as $row) {
            $avgReward     = $row->pulls > 0 ? $row->total_reward / $row->pulls : 0.5;
            $costPenalty   = min(1.0, $row->avg_cost_usd / 0.05);    // normalise against $0.05 ceiling
            $latencyPenalty = min(1.0, $row->avg_latency_ms / 30000); // normalise against 30s

            $score = ($avgReward * 0.7) - ($costPenalty * 0.2) - ($latencyPenalty * 0.1);
            $score = max(0.0, min(1.0, $score));

            DB::table('model_performance')
                ->where('id', $row->id)
                ->update(['score' => $score]);
        }
    }

    private static function defaultModel(string $taskType): string
    {
        return match ($taskType) {
            'content', 'writing', 'creative'  => 'claude-sonnet-4-6',
            'analysis', 'strategy', 'planning' => 'gpt-4o',
            'classification', 'routing'        => 'gpt-4o-mini',
            'hiring', 'structured'             => 'claude-haiku-4-5-20251001',
            'vision', 'media'                  => 'gpt-4o',
            default                            => config('agents.openai.default_model', 'gpt-4o'),
        };
    }
}
