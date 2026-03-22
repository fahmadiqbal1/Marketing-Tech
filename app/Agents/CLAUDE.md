# Agent System Rules — Marketing-Tech

> Domain-specific rules for BaseAgent, ContentAgent, and IterationEngineService.
> Root rules: @../../CLAUDE.md

---

## Agent Execution Flow
```
Queue Job (RunAgentJob) → BaseAgent::handle()
  → buildPromptContext()       (RAG from knowledge_base + iteration patterns)
  → AI loop (tool calls)
    → executeToolWithReliability()
        → isToolBlocked()      (circuit breaker check)
        → executeTool()
        → recordToolOutcome()  (updates failure streak)
  → storeOutput()              (only if variation exists)
  → syncOutputWinner()
```

## Circuit Breaker (IterationEngineService)
- `isToolBlocked($name)` — cache key `tool:blocked:{name}`
- `recordToolOutcome($name, $success)` — increments `tool:fail_streak:{name}`
- Trips after **5 consecutive failures**, blocks for **120 seconds**
- Never modify circuit breaker thresholds without updating this doc

## Variation Label Assumptions
Never assume variation label `A` exists. Always query the earliest/any variation for a job:
```php
ContentVariation::where('agent_job_id', $job->id)->orderBy('created_at')->value('id')
```

## Tool Gating by task_type
ContentAgent social tools are gated. Tool gating order:
1. Check `$this->job->task_type` — if set, use it
2. Fall back to keyword regex on `$this->job->instruction` if task_type is NULL (backward compat)
3. NULL task_type + no keyword match → return helpful error (not silent failure)

Social tools: `hashtag_strategy`, `trend_analysis`, `cross_platform_adapt`,
`create_content_calendar`, `select_hashtags`

Always-available tools: `keyword_research`, `generate_content`, `check_seo`,
`save_to_knowledge`, `search_knowledge`, `publish_content`, `repurpose_content`, `analyse_content`

## Platform/Content-Type Validation Matrix
Some platform/content_type combos are invalid. Check before saving to content_calendar:
```php
$invalid = [
    'instagram' => ['thread'],
    'twitter'   => ['reel', 'story', 'carousel'],
    'linkedin'  => ['reel', 'story'],
    'facebook'  => ['thread'],
];
```
Return a helpful validation error, not a DB constraint error.

## IterationEngine Integration Points
When ContentAgent produces output tied to a calendar entry:
1. Link `content_calendar.content_variation_id` to the variation at creation time
2. On publish: call `IterationEngineService::recordPerformance($variationId, $impressions, $clicks, $conversions, $source)`
3. Source tag: `'real'` when from live API, `'simulated'` when generated
4. RepurposeContent job uses top-performing variations — never repurpose without variation_id link

## ProcessTrends Anti-Spam Rules
- Log BEFORE acting: write SystemEvent with "Would create job for trend: {topic}" first
- Cache key `auto-trend-action:{platform}:{topic_hash}:last_run` — 12h cooldown
- All ProcessTrends-created calendar entries use `moderation_status=pending` (require human approval)
- Never auto-publish from trend analysis — always goes through pending_approval workflow
