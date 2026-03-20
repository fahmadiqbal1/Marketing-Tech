<?php

namespace App\AgentSystem\Tools;

interface ToolInterface
{
    /** Machine-readable name used by the agent (e.g. "GenerateContentTool"). */
    public function getName(): string;

    /** One-sentence description so the LLM knows when to invoke this tool. */
    public function getDescription(): string;

    /**
     * JSON Schema describing the expected parameters.
     * The agent uses this to fill the "parameters" field correctly.
     *
     * @return array  JSON-Schema compatible array
     */
    public function getParameterSchema(): array;

    /**
     * Execute the tool and return a structured result.
     *
     * @param  array  $parameters  Validated parameters from the agent decision.
     * @return array  Always returns ['success'=>bool, 'data'=>mixed, 'error'=>?string]
     */
    public function execute(array $parameters): array;
}
