# Agent System Rules — Marketing-Tech

> Domain-specific rules for BaseAgent, ContentAgent, and IterationEngineService.
> Root rules: @../../CLAUDE.md

---

## Agent Execution Flow
```
Queue Job (RunAgentJob) → BaseAgent::run()
  → PromptTemplateService::render()   (injects {date}, {business_name}, {agent_type})
  → buildInitialMessages()            (RAG context — RAGFlow-first → PageIndex → ILIKE)
  → AI loop (tool calls):
    → getAllToolDefinitions()          (agent tools + universal mcp_tool)
    → callAI()                        (AIRouter → provider)
    → executeToolWithReliability()
        → isToolBlocked()             (circuit breaker check)
        → dispatchTool()              (MCP intercept OR executeTool)
        → validateToolResult()        (schema check — UnexpectedValueException = retry)
        → recordToolOutcome()         (updates failure streak)
    → compressHistory()               (every maxHistorySteps — gpt-4o-mini summary)
  → persistSessionLearnings()         (3 key learnings → knowledge_base 'agent-skills')
  → advanceWorkflowDag()              (if workflow_id — unblocks dependent steps)
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

## New Agent Patterns (added 2026-04-27)

### History Compression
`BaseAgent::compressHistory()` is called every `maxHistorySteps` (default 8). It summarises
the oldest 4 message pairs via gpt-4o-mini (200 tokens), replacing them with a single
`[COMPRESSED HISTORY]` user message. Keeps context bounded without losing agent memory.

### Cross-Job Learning
`BaseAgent::persistSessionLearnings()` is called after every successful agent run. Extracts
3 key learnings via gpt-4o-mini and stores them in `knowledge_base` under category
`agent-skills`. These are retrieved in future runs via `getRagCategories()`.

### MCP Tool Routing
Every agent now has a universal `mcp_tool` in its tool definitions (via `baseToolDefinitions()`).
`dispatchTool()` intercepts `mcp_tool` calls and routes them via `McpToolService::execute()`.
All other tool names fall through to the agent's own `executeTool()`.

### Tool Result Validation
`BaseAgent::validateToolResult()` is called after `dispatchTool()`. Subclasses override
`getToolResultSchemas()` to declare expected keys. `\UnexpectedValueException` causes the
retry loop to re-attempt — same as a runtime exception.

### Configurable Context Budget
No more `const MAX_CONTEXT_CHARS`. Per-agent budgets come from `config/agents.php`:
- `context_budget_chars` — total chars for context building
- `context_reserved_chars` — headroom reserved for the response
Section limits are calculated proportionally at runtime.

### Prompt Templating
All system prompts use `{date}`, `{business_name}`, `{agent_type}` placeholders.
`PromptTemplateService::render()` is called in `run()` before the first AI call.
Add new placeholders by updating the `$vars` array in `BaseAgent::run()`.

### Workflow DAG
`AgentOrchestrator::dispatchWorkflow(steps, userId, chatId, name)` creates a `Workflow` +
`WorkflowTask` rows and dispatches unblocked steps immediately. `RunAgentJob::advanceWorkflowDag()`
checks for newly unblocked dependent tasks on completion. API: `POST /agent/workflows`.

---

## Tool Gating by task_type
ContentAgent social tools are gated. Tool gating order:
1. Check `$this->job->task_type` — if set, use it
2. Fall back to **LLM classification** (`classifyAsSocial()`, cached 1h) for NULL task_type
3. NULL task_type + LLM says false → return helpful error (not silent failure)

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
