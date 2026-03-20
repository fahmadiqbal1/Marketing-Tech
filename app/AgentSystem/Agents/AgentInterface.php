<?php

namespace App\AgentSystem\Agents;

use App\Models\AgentTask;

interface AgentInterface
{
    /** Human-readable name of this agent. */
    public function getName(): string;

    /** One-sentence specialisation description. */
    public function getSpecialisation(): string;

    /**
     * Execute an objective and return structured results.
     *
     * @param  string     $objective   What the master agent wants this sub-agent to accomplish.
     * @param  AgentTask  $task        Parent task for DB recording.
     * @param  array      $context     Any extra data the master agent passes down.
     * @return array  ['success'=>bool, 'data'=>mixed, 'steps_used'=>int, 'error'=>?string]
     */
    public function execute(string $objective, AgentTask $task, array $context = []): array;
}
