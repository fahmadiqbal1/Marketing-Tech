<?php

namespace App\AgentSystem\Tools;

use App\AgentSystem\Gateway\AIGateway;

/**
 * Central registry for all available agent tools.
 * Instantiates and caches tool instances; provides the tool catalogue
 * to the LLM system prompt.
 */
class ToolRegistry
{
    /** @var ToolInterface[] */
    private array $tools = [];

    public function __construct(private readonly AIGateway $gateway)
    {
        $this->register([
            new GenerateContentTool($gateway),
            new GenerateKeywordsTool($gateway),
            new AudienceAnalysisTool($gateway),
            new CompetitorAnalysisTool($gateway),
            new CampaignPlannerTool($gateway),
            new SummarizeTool($gateway),
        ]);
    }

    private function register(array $tools): void
    {
        foreach ($tools as $tool) {
            $this->tools[$tool->getName()] = $tool;
        }
    }

    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /** @return ToolInterface[] */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * Serialise tool catalogue into a compact string for the system prompt.
     */
    public function getCatalogueDescription(): string
    {
        $lines = [];
        foreach ($this->tools as $tool) {
            $params = array_map(
                fn($k, $v) => "{$k} ({$v['type']}" . ($v['required'] ? ', required' : ', optional') . "): {$v['description']}",
                array_keys($tool->getParameterSchema()),
                $tool->getParameterSchema()
            );
            $lines[] = "- **{$tool->getName()}**: {$tool->getDescription()}\n  Parameters: " . implode('; ', $params);
        }
        return implode("\n", $lines);
    }

    /**
     * Execute a named tool with a per-tool timeout guard.
     * Prevents a single slow tool from blocking the entire job.
     */
    public function execute(string $toolName, array $parameters, int $timeoutSeconds = 60): array
    {
        $tool = $this->get($toolName);

        if (! $tool) {
            return [
                'success' => false,
                'data'    => null,
                'error'   => "Tool '{$toolName}' not found. Available: " . implode(', ', array_keys($this->tools)),
            ];
        }

        // PCNTL alarm-based timeout (Linux / queue workers only)
        if (function_exists('pcntl_signal') && function_exists('pcntl_alarm')) {
            $timedOut = false;

            pcntl_signal(SIGALRM, function () use (&$timedOut) {
                $timedOut = true;
            });
            pcntl_alarm($timeoutSeconds);

            try {
                $result = $tool->execute($parameters);
            } catch (\Throwable $e) {
                pcntl_alarm(0);
                throw $e;
            }

            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);

            if ($timedOut) {
                return [
                    'success' => false,
                    'data'    => null,
                    'error'   => "Tool '{$toolName}' timed out after {$timeoutSeconds}s.",
                ];
            }

            return $result;
        }

        // Fallback (no PCNTL): execute without timeout guard
        return $tool->execute($parameters);
    }
}
