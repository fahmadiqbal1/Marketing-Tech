<?php

namespace App\Services;

use App\Services\AI\BanditModelSelector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Closes the learning loop: scores past decisions, updates model rewards,
 * and feeds outcome signals back into the bandit selector.
 */
class StrategicLearningService
{
    /**
     * Score a strategic decision once its outcome metrics arrive.
     * Outcome score = weighted average of related agent_outcomes.
     */
    public function scoreDecision(string $decisionId): void
    {
        $decision = DB::table('strategic_decisions')->where('id', $decisionId)->first();
        if (! $decision || $decision->outcome_score !== null) {
            return;
        }

        $outcomes = DB::table('agent_outcomes')
            ->where(DB::raw("metadata->>'strategic_decision_id'"), $decisionId)
            ->get();

        if ($outcomes->isEmpty()) {
            return;
        }

        $avgValue = $outcomes->avg('value');
        $score    = round(max(0.0, min(1.0, $avgValue)), 4);

        DB::table('strategic_decisions')
            ->where('id', $decisionId)
            ->update(['outcome_score' => $score, 'updated_at' => now()]);

        Log::info('StrategicLearningService: decision scored', [
            'decision_id'  => $decisionId,
            'outcome_score'=> $score,
        ]);
    }

    /**
     * Record a completed agent job's reward into the bandit model selector.
     * Call this from RunAgentJob after a successful run.
     */
    public function recordModelReward(
        string $modelName,
        string $agentType,
        bool   $success,
        float  $latencyMs,
        float  $costUsd,
        ?float $qualityScore = null,  // from outcome, null = unknown
    ): void {
        // Reward formula: success×0.5 + quality×0.3 - cost_penalty×0.1 - latency_penalty×0.1
        $successReward  = $success ? 0.5 : 0.0;
        $qualityReward  = ($qualityScore ?? 0.5) * 0.3;
        $costPenalty    = min(0.1, $costUsd / 0.10 * 0.1);
        $latencyPenalty = min(0.1, $latencyMs / 60000 * 0.1);

        $reward = max(0.0, $successReward + $qualityReward - $costPenalty - $latencyPenalty);

        BanditModelSelector::recordReward($modelName, $agentType, $reward, $latencyMs, $costUsd);
    }

    /**
     * Process user feedback (approval/edit/rejection) and extract a learning signal.
     * Stores to agent_feedback and updates domain ROI score.
     */
    public function processFeedback(
        string  $agentJobId,
        string  $userAction,    // approved|edited|rejected|regenerated
        ?array  $diff = null,   // before/after strings for edits
        ?string $domain = null,
    ): void {
        DB::table('agent_feedback')->insert([
            'id'           => \Illuminate\Support\Str::uuid(),
            'agent_job_id' => $agentJobId,
            'user_action'  => $userAction,
            'diff'         => $diff ? json_encode($diff) : null,
            'created_at'   => now(),
        ]);

        // Map action → ROI signal
        $signal = match ($userAction) {
            'approved'     => 0.8,
            'edited'       => 0.5,
            'regenerated'  => 0.3,
            'rejected'     => 0.1,
            default        => 0.5,
        };

        if ($domain) {
            $this->updateDomainRoi($domain, $signal);
        }
    }

    /**
     * Record an outcome metric for a completed execution.
     */
    public function recordOutcome(
        string  $domain,
        string  $metric,
        float   $value,
        ?string $agentJobId = null,
        ?string $entityId   = null,
        array   $metadata   = [],
    ): void {
        DB::table('agent_outcomes')->insert([
            'id'           => \Illuminate\Support\Str::uuid(),
            'agent_job_id' => $agentJobId,
            'domain'       => $domain,
            'entity_id'    => $entityId,
            'metric'       => $metric,
            'value'        => max(0.0, min(1.0, $value)),
            'metadata'     => json_encode($metadata),
            'created_at'   => now(),
        ]);
    }

    /**
     * Recalculate domain ROI scores from recent outcomes (last 30 days).
     */
    public function recalculateDomainRoi(): void
    {
        $domains = DB::table('agent_outcomes')
            ->select('domain', DB::raw('AVG(value) as avg_value'))
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('domain')
            ->get();

        foreach ($domains as $row) {
            BudgetAllocator::updateRoi($row->domain, $row->avg_value);
        }
    }

    private function updateDomainRoi(string $domain, float $signal): void
    {
        $current = DB::table('budget_allocations')
            ->where('domain', $domain)
            ->value('roi_score') ?? 0.5;

        // Exponential moving average (α = 0.15)
        $newRoi = round(0.85 * $current + 0.15 * $signal, 4);
        BudgetAllocator::updateRoi($domain, $newRoi);
    }
}
