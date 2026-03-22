# Database Rules — Marketing-Tech

> Domain-specific rules for migrations, models, and vector queries.
> Root rules: @../../CLAUDE.md

---

## Model vs Migration Alignment
Before touching a model, check if the migration already has a column the model doesn't use.
```bash
grep -r 'deleted_at\|softDeletes' database/migrations/ app/Models/
```
`deleted_at` existed in DB for `knowledge_base` before the model had `SoftDeletes` — caused silent data loss.

## SQL Injection in Raw DB Queries
All pgvector queries use raw SQL (`selectRaw`, `whereRaw`, `orderByRaw`).
- **Always** sanitize: `array_map('floatval', $embedding)` before building `[f1,f2,...]` string
- **Always** use `?` PDO bindings — never string-interpolate user or external data

```php
// CORRECT
$vec = '[' . implode(',', array_map('floatval', $embedding)) . ']';
DB::selectRaw("... embedding <=> ?::vector ...", [$vec]);

// WRONG — SQL injection risk
DB::selectRaw("... embedding <=> '{$vec}'::vector ...");
```

## Vector Search (KnowledgeBase::semanticSearch)
- Cosine similarity threshold: **0.65**
- Cache key pattern: none — always live query
- `Candidate::search()` — same sanitize pattern (fixed commit 5e038fa)

## KnowledgeBase Soft Deletes
- `deleted_at` column exists since original migration
- Model uses `SoftDeletes` — all `->delete()` calls set `deleted_at` (not hard delete)
- 50k cap pruning archives (soft deletes) instead of hard deleting training data

## AgentSkillsSeeder Idempotency
- Category stored as `'agent-skills'` NOT the agent's name
- When querying knowledge counts per agent, check BOTH:
  - `category = 'agent-skills'` WHERE `title LIKE '%agentname%'`
  - `category = agentname` directly
- Seeder computes `md5` of first 1000 chars — only skips if `content_hash` matches
- Changed manifests trigger soft-delete of old entry + re-store (safe to run multiple times)

## DB Integrity Constraints (Phase F migrations)
- `agent_steps.agent_job_id` — NOT NULL + FK → `agent_jobs` CASCADE DELETE
- `generated_outputs.content_variation_id` — NOT NULL + FK → `content_variations` CASCADE DELETE

## Migration Down Methods
Every new migration MUST have a `down()` that is the exact reverse of `up()`.
```php
public function down(): void { Schema::dropIfExists('table_name'); }
// or for addColumn: Schema::table('t', fn($t) => $t->dropColumn('col'));
```
