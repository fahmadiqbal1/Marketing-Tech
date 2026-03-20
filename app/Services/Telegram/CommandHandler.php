<?php

namespace App\Services\Telegram;

use App\Agents\AgentOrchestrator;
use App\Models\AgentJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CommandHandler
{
    public function __construct(
        private readonly TelegramBotService $bot,
        private readonly AgentOrchestrator  $orchestrator,
    ) {}

    public function handle(string $text, array $message, int $chatId, int $userId): void
    {
        // Extract command and args — strip @BotName suffix
        $parts   = explode(' ', trim($text), 2);
        $command = strtolower(explode('@', $parts[0])[0]);
        $args    = $parts[1] ?? '';

        match ($command) {
            '/start'     => $this->handleStart($chatId),
            '/help'      => $this->handleHelp($chatId),
            '/status'    => $this->handleStatus($chatId),
            '/jobs'      => $this->handleJobs($chatId),
            '/campaign'  => $this->handleCampaign($args, $chatId, $userId),
            '/content'   => $this->handleContent($args, $chatId, $userId),
            '/media'     => $this->handleMedia($args, $chatId, $userId),
            '/hire'      => $this->handleHire($args, $chatId, $userId),
            '/growth'    => $this->handleGrowth($args, $chatId, $userId),
            '/knowledge' => $this->handleKnowledge($args, $chatId, $userId),
            '/agent'     => $this->handleAgent($args, $chatId, $userId),
            '/cancel'    => $this->handleCancel($args, $chatId),
            '/logs'      => $this->handleLogs($chatId),
            default      => $this->bot->sendMessage($chatId, "❓ Unknown command. Use /help to see available commands."),
        };
    }

    private function handleStart(int $chatId): void
    {
        $this->bot->sendMessage($chatId, <<<MSG
🤖 *Autonomous Business Operations Platform*

I automate your business operations. I can handle:

📣 *Marketing* — campaigns, email, ads, A/B tests
✍️ *Content* — blog posts, social media, ad copy
🎬 *Media* — video/image processing, OCR
👥 *Hiring* — CV screening, outreach, pipeline
📊 *Growth* — experiments, analytics, reports
🧠 *Knowledge* — store and retrieve business intelligence

Use /help to see all commands, or just type a natural language instruction.
MSG
        );
    }

    private function handleHelp(int $chatId): void
    {
        $commands = config('agents.telegram.commands', []);
        $lines    = ["*Available Commands:*\n"];
        foreach ($commands as $cmd => $desc) {
            $lines[] = "`{$cmd}` — {$desc}";
        }
        $lines[] = "\nOr just type a task in plain English — I'll route it automatically.";
        $this->bot->sendMessage($chatId, implode("\n", $lines));
    }

    private function handleStatus(int $chatId): void
    {
        $pendingCount  = AgentJob::where('status', 'pending')->count();
        $runningCount  = AgentJob::where('status', 'running')->count();
        $failedCount   = AgentJob::where('status', 'failed')
            ->where('created_at', '>=', now()->subDay())
            ->count();
        $completedToday = AgentJob::where('status', 'completed')
            ->whereDate('completed_at', today())
            ->count();

        $horizonStatus = $this->getHorizonStatus();

        $this->bot->sendMessage($chatId, <<<MSG
📊 *System Status*

*Queue Health:* {$horizonStatus}
⏳ Pending jobs: {$pendingCount}
▶️ Running jobs: {$runningCount}
✅ Completed today: {$completedToday}
❌ Failed (24h): {$failedCount}

*Infrastructure:*
🔴 Redis: {$this->pingRedis()}
🟢 Postgres: {$this->pingPostgres()}

Use /jobs to see active job details.
MSG
        );
    }

    private function handleJobs(int $chatId): void
    {
        $jobs = AgentJob::whereIn('status', ['pending', 'running'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        if ($jobs->isEmpty()) {
            $this->bot->sendMessage($chatId, '✅ No active jobs.');
            return;
        }

        $lines = ["*Active Jobs:*\n"];
        foreach ($jobs as $job) {
            $icon = $job->status === 'running' ? '▶️' : '⏳';
            $lines[] = "{$icon} `{$job->id}` — {$job->agent_type}: {$job->short_description}";
            $lines[] = "   Started: {$job->created_at->diffForHumans()}";
        }

        $buttons = $jobs->map(fn($j) => [
            ['text' => "Cancel {$j->id}", 'callback_data' => "cancel_job:{$j->id}"],
        ])->toArray();

        $this->bot->sendMessage($chatId, implode("\n", $lines), $this->bot->inlineKeyboard($buttons));
    }

    private function handleCampaign(string $args, int $chatId, int $userId): void
    {
        if (empty($args)) {
            $this->bot->sendMessage($chatId, <<<MSG
📣 *Campaign Manager*

Usage: `/campaign <instruction>`

Examples:
• `/campaign Create email campaign for product launch next Monday`
• `/campaign Analyse performance of last 30 days campaigns`
• `/campaign Generate A/B test subject lines for newsletter`
MSG
            );
            return;
        }

        $this->dispatchToAgent('marketing', $args, $chatId, $userId);
    }

    private function handleContent(string $args, int $chatId, int $userId): void
    {
        if (empty($args)) {
            $this->bot->sendMessage($chatId, <<<MSG
✍️ *Content Generator*

Usage: `/content <instruction>`

Examples:
• `/content Write LinkedIn post about our Q3 results`
• `/content Generate 5 Twitter thread ideas about AI`
• `/content Create product description for new SaaS feature`
MSG
            );
            return;
        }

        $this->dispatchToAgent('content', $args, $chatId, $userId);
    }

    private function handleMedia(string $args, int $chatId, int $userId): void
    {
        if (empty($args)) {
            $this->bot->sendMessage($chatId, <<<MSG
🎬 *Media Processor*

Usage: `/media <instruction>` then attach a file

Examples:
• `/media Transcode to web-optimised MP4` (then send video)
• `/media Extract text from PDF` (then send document)
• `/media Resize to 1200x630 for social` (then send image)
MSG
            );
            return;
        }

        $this->dispatchToAgent('media', $args, $chatId, $userId);
    }

    private function handleHire(string $args, int $chatId, int $userId): void
    {
        if (empty($args)) {
            $this->bot->sendMessage($chatId, <<<MSG
👥 *Hiring Pipeline*

Usage: `/hire <instruction>`

Examples:
• `/hire Score attached CV for Senior Backend Engineer role`
• `/hire Draft outreach email for top 5 candidates`
• `/hire Show pipeline summary for all open roles`
• `/hire Create job description for Product Manager`
MSG
            );
            return;
        }

        $this->dispatchToAgent('hiring', $args, $chatId, $userId);
    }

    private function handleGrowth(string $args, int $chatId, int $userId): void
    {
        if (empty($args)) {
            $this->bot->sendMessage($chatId, <<<MSG
📊 *Growth Engine*

Usage: `/growth <instruction>`

Examples:
• `/growth Create experiment: onboarding flow A/B test`
• `/growth Analyse conversion funnel last 30 days`
• `/growth What experiments are currently running?`
• `/growth Weekly growth report`
MSG
            );
            return;
        }

        $this->dispatchToAgent('growth', $args, $chatId, $userId);
    }

    private function handleKnowledge(string $args, int $chatId, int $userId): void
    {
        if (empty($args)) {
            $this->bot->sendMessage($chatId, <<<MSG
🧠 *Knowledge Base*

Usage: `/knowledge <query or instruction>`

Examples:
• `/knowledge What is our brand voice guidelines?`
• `/knowledge Store: Our ICP is B2B SaaS companies 50-500 employees`
• `/knowledge Find all documents about pricing strategy`
MSG
            );
            return;
        }

        $this->dispatchToAgent('knowledge', $args, $chatId, $userId);
    }

    private function handleAgent(string $args, int $chatId, int $userId): void
    {
        if (empty($args)) {
            $this->bot->sendMessage($chatId, 'Usage: `/agent <free-form task description>`');
            return;
        }

        $this->orchestrator->dispatchFromTelegram(
            instruction: $args,
            chatId:      $chatId,
            userId:      $userId,
            messageId:   null,
        );
    }

    private function handleCancel(string $args, int $chatId): void
    {
        $jobId = trim($args);
        if (empty($jobId)) {
            $this->bot->sendMessage($chatId, 'Usage: `/cancel <job_id>`');
            return;
        }
        $this->cancelJob($jobId, $chatId);
    }

    public function cancelJob(string $jobId, int $chatId): void
    {
        $job = AgentJob::find($jobId);
        if (! $job) {
            $this->bot->sendMessage($chatId, "❌ Job `{$jobId}` not found.");
            return;
        }

        if ($job->status === 'completed') {
            $this->bot->sendMessage($chatId, "✅ Job `{$jobId}` already completed.");
            return;
        }

        $job->update(['status' => 'cancelled']);
        $this->bot->sendMessage($chatId, "🛑 Job `{$jobId}` cancelled.");
    }

    public function confirmAndRun(string $payload, int $chatId, int $userId): void
    {
        $this->orchestrator->dispatchFromTelegram(
            instruction: base64_decode($payload),
            chatId:      $chatId,
            userId:      $userId,
            messageId:   null,
        );
    }

    public function viewResult(string $jobId, int $chatId): void
    {
        $job = AgentJob::find($jobId);
        if (! $job || empty($job->result)) {
            $this->bot->sendMessage($chatId, "Result not found for job `{$jobId}`.");
            return;
        }

        $this->bot->sendMessage($chatId, substr($job->result, 0, 4000));
    }

    private function handleLogs(int $chatId): void
    {
        $recentErrors = AgentJob::where('status', 'failed')
            ->orderBy('updated_at', 'desc')
            ->limit(5)
            ->get(['id', 'agent_type', 'error_message', 'updated_at']);

        if ($recentErrors->isEmpty()) {
            $this->bot->sendMessage($chatId, '✅ No recent errors.');
            return;
        }

        $lines = ["🔴 *Recent Errors:*\n"];
        foreach ($recentErrors as $job) {
            $lines[] = "`{$job->id}` [{$job->agent_type}] {$job->updated_at->diffForHumans()}";
            $lines[] = "   " . Str::limit($job->error_message, 100);
        }

        $this->bot->sendMessage($chatId, implode("\n", $lines));
    }

    private function dispatchToAgent(string $agentType, string $instruction, int $chatId, int $userId): void
    {
        $this->bot->sendTypingIndicator($chatId);
        $this->bot->sendMessage($chatId, "⚡ Dispatching to {$agentType} agent...");

        $this->orchestrator->dispatch(
            agentType:   $agentType,
            instruction: $instruction,
            chatId:      $chatId,
            userId:      $userId,
        );
    }

    private function getHorizonStatus(): string
    {
        try {
            $status = app(\Laravel\Horizon\Contracts\MasterSupervisorRepository::class)->all();
            return empty($status) ? '🔴 Offline' : '🟢 Running';
        } catch (\Throwable) {
            return '⚠️ Unknown';
        }
    }

    private function pingRedis(): string
    {
        try {
            \Illuminate\Support\Facades\Redis::ping();
            return '🟢 Connected';
        } catch (\Throwable) {
            return '🔴 Disconnected';
        }
    }

    private function pingPostgres(): string
    {
        try {
            \Illuminate\Support\Facades\DB::select('SELECT 1');
            return '🟢 Connected';
        } catch (\Throwable) {
            return '🔴 Disconnected';
        }
    }
}
