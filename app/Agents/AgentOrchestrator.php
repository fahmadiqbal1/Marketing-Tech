<?php

namespace App\Agents;

use App\Jobs\RunAgentJob;
use App\Models\AgentJob;
use App\Models\Workflow;
use App\Models\WorkflowTask;
use App\Services\AI\OpenAIService;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AgentOrchestrator
{
    private array $agentMap = [
        'marketing' => MarketingAgent::class,
        'content'   => ContentAgent::class,
        'media'     => MediaAgent::class,
        'hiring'    => HiringAgent::class,
        'growth'    => GrowthAgent::class,
        'knowledge' => KnowledgeAgent::class,
    ];

    public function __construct(
        private readonly OpenAIService $openai,
    ) {}

    /**
     * Dispatch a task to a specific agent type.
     */
    public function dispatch(
        string $agentType,
        string $instruction,
        int    $chatId,
        int    $userId,
    ): AgentJob {
        $agentClass = $this->agentMap[$agentType] ?? null;

        if (! $agentClass) {
            throw new \InvalidArgumentException("Unknown agent type: {$agentType}");
        }

        $job = AgentJob::create([
            'id'                => (string) Str::uuid(),
            'agent_type'        => $agentType,
            'agent_class'       => $agentClass,
            'instruction'       => $instruction,
            'short_description' => Str::limit($instruction, 80),
            'status'            => 'pending',
            'chat_id'           => $chatId,
            'user_id'           => $userId,
            'metadata'          => [],
        ]);

        $queue = config("agents.agents.{$agentType}.queue", 'default');

        RunAgentJob::dispatch($job->id)->onQueue($queue);

        Log::info("Agent job dispatched", [
            'job_id'     => $job->id,
            'agent_type' => $agentType,
            'chat_id'    => $chatId,
        ]);

        return $job;
    }

    /**
     * Dispatch from a free-form Telegram message — auto-route to best agent.
     */
    public function dispatchFromTelegram(
        string $instruction,
        int    $chatId,
        int    $userId,
        ?int   $messageId,
    ): AgentJob {
        $agentType = $this->classifyInstruction($instruction);

        Log::info("Auto-routed instruction", [
            'agent'       => $agentType,
            'instruction' => Str::limit($instruction, 100),
        ]);

        return $this->dispatch(
            agentType:   $agentType,
            instruction: $instruction,
            chatId:      $chatId,
            userId:      $userId,
        );
    }

    /**
     * Dispatch a multi-step agent workflow with optional DAG dependencies.
     *
     * Each step: ['agent_type' => string, 'instruction' => string, 'depends_on_step' => ?int (0-indexed)]
     * Steps with no dependency (or depends_on_step = null) start immediately.
     * Dependent steps are dispatched by RunAgentJob when their prerequisite completes.
     */
    public function dispatchWorkflow(array $steps, int $userId, int $chatId = 0, string $name = ''): Workflow
    {
        $workflow = Workflow::create([
            'id'            => (string) Str::uuid(),
            'name'          => $name ?: 'Workflow ' . now()->format('Y-m-d H:i'),
            'type'          => 'agent_dag',
            'status'        => Workflow::STATUS_TASK_EXECUTION,
            'user_id'       => $userId,
            'chat_id'       => $chatId,
            'input_payload' => ['steps' => $steps],
            'started_at'    => now(),
        ]);

        // First pass: create all WorkflowTask rows and collect task IDs by step index
        $taskIdsByStep = [];

        foreach ($steps as $index => $step) {
            $agentType = $step['agent_type'] ?? 'content';
            $task = WorkflowTask::create([
                'workflow_id' => $workflow->id,
                'name'        => "Step {$index}: {$agentType}",
                'type'        => 'agent_run',
                'status'      => 'pending',
                'sequence'    => $index,
                'agent_type'  => $agentType,
                'input'       => ['instruction' => $step['instruction'] ?? ''],
            ]);
            $taskIdsByStep[$index] = $task->id;
        }

        // Second pass: wire depends_on_task_id, then dispatch unblocked steps
        foreach ($steps as $index => $step) {
            $dependsOnStep = $step['depends_on_step'] ?? null;

            if ($dependsOnStep !== null && isset($taskIdsByStep[$dependsOnStep])) {
                WorkflowTask::where('id', $taskIdsByStep[$index])
                    ->update(['depends_on_task_id' => $taskIdsByStep[$dependsOnStep]]);
            } else {
                // No dependency — dispatch immediately
                $this->dispatchWorkflowStep($workflow, $taskIdsByStep[$index], $steps[$index], $userId, $chatId);
            }
        }

        Log::info('Workflow DAG dispatched', [
            'workflow_id' => $workflow->id,
            'steps'       => count($steps),
        ]);

        return $workflow;
    }

    /**
     * Dispatch a single workflow step as an AgentJob.
     * Called both at workflow start and by RunAgentJob on step completion.
     */
    public function dispatchWorkflowStep(Workflow $workflow, string $taskId, array $step, int $userId, int $chatId): AgentJob
    {
        $agentType  = $step['agent_type'] ?? 'content';
        $agentClass = $this->agentMap[$agentType] ?? ContentAgent::class;
        $queue      = config("agents.agents.{$agentType}.queue", 'default');

        $job = AgentJob::create([
            'id'                => (string) Str::uuid(),
            'workflow_id'       => $workflow->id,
            'agent_type'        => $agentType,
            'agent_class'       => $agentClass,
            'instruction'       => $step['instruction'] ?? '',
            'short_description' => Str::limit($step['instruction'] ?? '', 80),
            'status'            => 'pending',
            'chat_id'           => $chatId,
            'user_id'           => $userId,
            'metadata'          => ['workflow_task_id' => $taskId],
        ]);

        WorkflowTask::where('id', $taskId)->update([
            'status'     => 'running',
            'started_at' => now(),
            'metadata'   => ['agent_job_id' => $job->id],
        ]);

        RunAgentJob::dispatch($job->id)->onQueue($queue);

        return $job;
    }

    /**
     * Handle file upload from Telegram — route to media agent.
     */
    public function handleFileUpload(
        int    $chatId,
        int    $userId,
        string $fileId,
        string $fileName,
        string $mimeType,
        ?string $caption,
    ): AgentJob {
        $instruction = $caption
            ? "Process uploaded file: {$fileName} (MIME: {$mimeType}). Instruction: {$caption}"
            : "Process and store uploaded file: {$fileName} (MIME: {$mimeType})";

        return $this->dispatch(
            agentType:   'media',
            instruction: $instruction,
            chatId:      $chatId,
            userId:      $userId,
        );
    }

    /**
     * Use GPT-4o to classify which agent should handle this instruction.
     * Uses a fast classification call with strict JSON output.
     */
    private function classifyInstruction(string $instruction): string
    {
        $prompt = <<<PROMPT
You are a routing classifier. Given a user instruction, determine which agent type should handle it.

Agent types and their responsibilities:
- marketing: email campaigns, ads, A/B tests, campaign analysis
- content: writing blog posts, social media, ad copy, newsletters, scripts
- media: video/image processing, transcoding, OCR, file handling
- hiring: CV parsing, candidate scoring, job posts, outreach emails, pipeline
- growth: experiments, analytics, conversion funnels, metrics, reports
- knowledge: storing or retrieving information, facts, company knowledge

User instruction: "{$instruction}"

Respond with ONLY a JSON object:
{"agent": "one of: marketing, content, media, hiring, growth, knowledge"}
PROMPT;

        try {
            $response = $this->openai->complete(
                prompt:      $prompt,
                model:       'gpt-4o-mini',
                maxTokens:   50,
                temperature: 0.0,
                jsonMode:    true,
            );

            $data = json_decode($response, true);
            $agent = $data['agent'] ?? 'content';

            return isset($this->agentMap[$agent]) ? $agent : 'content';
        } catch (\Throwable $e) {
            Log::warning("Agent classification failed, defaulting to content", ['error' => $e->getMessage()]);
            return 'content';
        }
    }
}
