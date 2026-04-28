<?php

namespace App\Agents;

use App\Models\AgentJob;
use App\Services\AI\AIRouter;
use App\Services\InsightExtractionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Strategic Decision Engine — the judgment layer above AgentOrchestrator.
 *
 * This agent never executes tasks. It evaluates, modifies, prioritises, or blocks
 * requests before they reach the execution pipeline.
 *
 * Three modes (set via STRATEGIC_MODE env var):
 *   shadow   — runs silently, stores decisions, zero user impact (safe default)
 *   advisory — sends suggestions to Telegram before execution proceeds
 *   active   — can modify or block the instruction
 *
 * Integration: wrap dispatchFromTelegram() and campaign/hiring pipelines.
 * Always falls back to APPROVE when confidence < 0.65 or LLM fails.
 */
class StrategicAgent
{
    private const CONFIDENCE_GATE = 0.65;

    /**
     * Evaluate a user instruction and return a strategic decision.
     *
     * @param  string  $instruction  Raw user input
     * @param  array   $context      Optional caller-provided context (chatId, userId, domain hint)
     * @return array{
     *   action: string,
     *   modified_instruction: string|null,
     *   reasoning: string,
     *   confidence: float,
     *   priority: string,
     *   suggested_agents: string[],
     *   budget_level: string,
     *   risks: string[],
     *   expected_outcome: string
     * }
     */
    public static function evaluate(string $instruction, array $context = []): array
    {
        if (! config('agents.strategic_layer_enabled', false)) {
            return self::approvePassthrough($instruction);
        }

        try {
            $strategicContext = self::buildContext($instruction, $context);
            $decision         = self::callLlm($strategicContext);
            $decision         = self::validate($decision, $instruction);

            // Confidence gating — never block when not confident
            if ($decision['confidence'] < self::CONFIDENCE_GATE) {
                $decision['action'] = 'APPROVE';
                $decision['reasoning'] .= ' (confidence below threshold — defaulting to APPROVE)';
            }

            self::store($instruction, $decision, $strategicContext);

            return $decision;

        } catch (\Throwable $e) {
            Log::warning('StrategicAgent: evaluation failed, defaulting to APPROVE', [
                'error' => $e->getMessage(),
            ]);
            return self::approvePassthrough($instruction);
        }
    }

    /**
     * Apply a decision in a given mode. Returns the (possibly modified) instruction.
     * Also handles advisory Telegram notification.
     *
     * @param  array    $decision   Output of evaluate()
     * @param  string   $instruction Original instruction
     * @param  string   $mode       shadow|advisory|active
     * @param  callable $notifyFn   fn(string $message) — sends a Telegram message to the user
     * @return string|null  null = blocked; string = instruction to proceed with
     */
    public static function apply(array $decision, string $instruction, string $mode, callable $notifyFn): ?string
    {
        $action = $decision['action'] ?? 'APPROVE';

        if ($mode === 'shadow') {
            return $instruction; // never interfere
        }

        if ($mode === 'advisory') {
            $emoji   = match ($action) {
                'MODIFY'  => '✏️',
                'REJECT'  => '⚠️',
                'DELAY'   => '⏳',
                default   => '🧠',
            };
            $suggestion = "{$emoji} *Strategic insight:* " . $decision['reasoning'];
            if (! empty($decision['risks'])) {
                $suggestion .= "\n⚡ *Risks:* " . implode(', ', $decision['risks']);
            }
            $notifyFn($suggestion);
            return $instruction; // still proceed as-is in advisory mode
        }

        if ($mode === 'active') {
            return match ($action) {
                'REJECT' => self::handleReject($decision, $notifyFn),
                'DELAY'  => self::handleDelay($decision, $notifyFn),
                'MODIFY' => $decision['modified_instruction'] ?? $instruction,
                default  => $instruction,
            };
        }

        return $instruction;
    }

    // ── Private implementation ────────────────────────────────────────

    private static function buildContext(string $instruction, array $context): array
    {
        // Recent agent jobs (last 48 hours) — lightweight summary
        $recentJobs = AgentJob::where('created_at', '>=', now()->subHours(48))
            ->latest()
            ->limit(10)
            ->get(['agent_type', 'status', 'error_message', 'created_at'])
            ->map(fn ($j) => [
                'type'   => $j->agent_type,
                'status' => $j->status,
                'age_h'  => round($j->created_at->diffInHours(now()), 1),
            ])
            ->toArray();

        // Recent failure signals
        $failureRate = AgentJob::where('created_at', '>=', now()->subHours(24))
            ->where('status', 'failed')
            ->count();

        // Recent strategic decisions (last 5) — prevent duplicate decisions
        $recentDecisions = DB::table('strategic_decisions')
            ->latest()
            ->limit(5)
            ->get(['action', 'confidence', 'outcome_score', 'created_at'])
            ->map(fn ($d) => [
                'action'    => $d->action,
                'confidence'=> $d->confidence,
                'outcome'   => $d->outcome_score,
                'age_h'     => round(now()->diffInHours($d->created_at), 1),
            ])
            ->toArray();

        // Active insights from InsightExtractionService
        $domainHint  = $context['domain'] ?? 'general';
        $allInsights = DB::table('strategic_insights')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('confidence')
            ->limit(8)
            ->pluck('insight')
            ->toArray();

        // Budget status (advisory awareness)
        $budgets = DB::table('budget_allocations')
            ->get(['domain', 'daily_budget', 'used_today', 'roi_score'])
            ->map(fn ($b) => [
                'domain'    => $b->domain,
                'used_pct'  => $b->daily_budget > 0 ? round($b->used_today / $b->daily_budget * 100) : 0,
                'roi'       => round($b->roi_score, 2),
            ])
            ->toArray();

        return [
            'user_instruction'   => $instruction,
            'recent_jobs'        => $recentJobs,
            'failure_rate_24h'   => $failureRate,
            'recent_decisions'   => $recentDecisions,
            'active_insights'    => $allInsights,
            'budget_status'      => $budgets,
            'hint_domain'        => $domainHint,
        ];
    }

    private static function callLlm(array $strategicContext): array
    {
        $router   = app(AIRouter::class);
        $response = $router->complete(
            prompt:      json_encode($strategicContext, JSON_UNESCAPED_UNICODE),
            model:       config('agents.strategic_model', 'gpt-4o-mini'),
            maxTokens:   512,
            temperature: 0.15,
            system:      self::systemPrompt(),
        );

        // Strip markdown code fences if model wraps JSON
        $raw = trim(preg_replace('/^```(?:json)?\s*|\s*```$/s', '', trim($response)));

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('StrategicAgent: invalid JSON from LLM', ['raw' => substr($raw, 0, 200)]);
            return [];
        }

        return $decoded;
    }

    private static function validate(array $raw, string $fallbackInstruction): array
    {
        return [
            'action'               => in_array($raw['action'] ?? '', ['APPROVE', 'MODIFY', 'REJECT', 'DELAY', 'REQUEST_INFO'])
                                      ? $raw['action'] : 'APPROVE',
            'modified_instruction' => $raw['modified_instruction'] ?? null,
            'reasoning'            => $raw['reasoning'] ?? 'No reasoning provided.',
            'confidence'           => (float) ($raw['confidence'] ?? 0.5),
            'priority'             => in_array($raw['priority'] ?? '', ['low', 'medium', 'high'])
                                      ? $raw['priority'] : 'medium',
            'suggested_agents'     => (array) ($raw['suggested_agents'] ?? []),
            'budget_level'         => in_array($raw['budget_level'] ?? '', ['low', 'medium', 'high'])
                                      ? $raw['budget_level'] : 'medium',
            'risks'                => (array) ($raw['risks'] ?? []),
            'expected_outcome'     => $raw['expected_outcome'] ?? '',
        ];
    }

    private static function store(string $instruction, array $decision, array $context): void
    {
        try {
            DB::table('strategic_decisions')->insert([
                'id'             => \Illuminate\Support\Str::uuid(),
                'input'          => json_encode(['instruction' => $instruction, 'context_summary' => array_keys($context)]),
                'decision'       => json_encode($decision),
                'action'         => $decision['action'],
                'confidence'     => $decision['confidence'],
                'strategic_mode' => config('agents.strategic_mode', 'shadow'),
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('StrategicAgent: failed to store decision', ['error' => $e->getMessage()]);
        }
    }

    private static function handleReject(array $decision, callable $notifyFn): null
    {
        $msg = "🚫 *Request blocked by Strategic Engine*\n_{$decision['reasoning']}_";
        if (! empty($decision['risks'])) {
            $msg .= "\n⚡ " . implode(' · ', $decision['risks']);
        }
        $notifyFn($msg);
        return null;
    }

    private static function handleDelay(array $decision, callable $notifyFn): null
    {
        $msg = "⏳ *Strategic Engine suggests delaying this request*\n_{$decision['reasoning']}_";
        $notifyFn($msg);
        return null; // caller decides how to handle null (skip or queue for later)
    }

    private static function approvePassthrough(string $instruction): array
    {
        return [
            'action'               => 'APPROVE',
            'modified_instruction' => null,
            'reasoning'            => 'Strategic layer disabled or bypassed.',
            'confidence'           => 1.0,
            'priority'             => 'medium',
            'suggested_agents'     => [],
            'budget_level'         => 'medium',
            'risks'                => [],
            'expected_outcome'     => '',
        ];
    }

    private static function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a Strategic Decision Engine for an autonomous AI business platform.

Your role is to evaluate user requests and decide whether and how they should proceed.

You do NOT execute tasks. You ONLY make decisions about requests.

Context you receive (JSON):
- user_instruction: the raw request
- recent_jobs: what agents have been doing recently
- failure_rate_24h: recent system failures
- recent_decisions: your past decisions (avoid repetition)
- active_insights: known performance patterns (trust these)
- budget_status: per-domain spend utilisation + ROI scores
- hint_domain: likely execution domain

Your decision criteria:
1. Avoid redundant campaigns (check recent_decisions)
2. Flag high-risk, vague, or low-ROI requests
3. Improve specificity of weak instructions (MODIFY, don't REJECT)
4. Respect budget signals — if a domain is over-utilised or low ROI, flag it
5. Leverage insights — if patterns show what works, use them to enrich MODIFY actions
6. Be conservative: APPROVE when uncertain. REJECT only when clearly harmful or wasteful.
7. Confidence below 0.65 will be auto-overridden to APPROVE, so be honest about confidence.

Return ONLY valid JSON — no markdown, no explanation outside the JSON:
{
  "action": "APPROVE|MODIFY|REJECT|DELAY|REQUEST_INFO",
  "modified_instruction": "string or null",
  "reasoning": "1-2 sentences",
  "confidence": 0.0-1.0,
  "priority": "low|medium|high",
  "suggested_agents": ["content", "marketing", ...],
  "budget_level": "low|medium|high",
  "risks": ["string", ...],
  "expected_outcome": "string"
}
PROMPT;
    }
}
