<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Persists and retrieves key observations made by agents during task execution.
 *
 * Safety limits:
 *   - all() returns at most 10 most recent memories
 *   - values are truncated to 500 chars before injection into prompts
 *   - no cross-task memory sharing (scoped to agent_task_id)
 */
class MemoryService
{
    private const MAX_ENTRIES    = 10;
    private const MAX_VALUE_LEN  = 500;

    /**
     * Store a memory entry for a task.
     * If the same key already exists, it is replaced.
     */
    public function store(int $taskId, string $key, string $value, ?string $context = null): void
    {
        try {
            // Delete any existing entry for this key first
            DB::table('agent_memories')
                ->where('agent_task_id', $taskId)
                ->where('memory_key', $key)
                ->delete();

            DB::table('agent_memories')->insert([
                'agent_task_id' => $taskId,
                'memory_key'    => $key,
                'value'         => $value,
                'context'       => $context,
                'created_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("[MemoryService] Failed to store memory for task {$taskId}: " . $e->getMessage());
        }
    }

    /**
     * Retrieve a specific memory value by key.
     */
    public function retrieve(int $taskId, string $key): ?string
    {
        try {
            return DB::table('agent_memories')
                ->where('agent_task_id', $taskId)
                ->where('memory_key', $key)
                ->value('value');
        } catch (\Throwable $e) {
            Log::warning("[MemoryService] Failed to retrieve memory for task {$taskId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Return up to MAX_ENTRIES most recent memories for a task.
     * Values are truncated to MAX_VALUE_LEN to prevent token bloat.
     *
     * @return array<string, string>  [key => truncated_value]
     */
    public function all(int $taskId): array
    {
        try {
            $rows = DB::table('agent_memories')
                ->where('agent_task_id', $taskId)
                ->orderByDesc('created_at')
                ->limit(self::MAX_ENTRIES)
                ->get(['memory_key', 'value', 'context']);

            $result = [];
            foreach ($rows as $row) {
                $result[$row->memory_key] = mb_substr($row->value, 0, self::MAX_VALUE_LEN);
            }
            return $result;
        } catch (\Throwable $e) {
            Log::warning("[MemoryService] Failed to load memories for task {$taskId}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Delete all memories for a task.
     */
    public function clear(int $taskId): void
    {
        try {
            DB::table('agent_memories')->where('agent_task_id', $taskId)->delete();
        } catch (\Throwable $e) {
            Log::warning("[MemoryService] Failed to clear memories for task {$taskId}: " . $e->getMessage());
        }
    }
}
