<?php

namespace App\Providers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Shared infrastructure services
        $this->app->singleton(\App\Services\ApiCredentialService::class);
        $this->app->singleton(\App\Services\MemoryService::class);

        // AI Services
        $this->app->singleton(\App\Services\AI\CostCalculatorService::class);
        $this->app->singleton(\App\Services\AI\OpenAIService::class);
        $this->app->singleton(\App\Services\AI\AnthropicService::class);
        $this->app->singleton(\App\Services\AI\AIRouter::class);

        // Dashboard
        $this->app->singleton(\App\Services\Dashboard\DashboardStatsService::class);

        // Telegram
        $this->app->singleton(\App\Services\Telegram\TelegramBotService::class);
        $this->app->singleton(\App\Services\Telegram\CommandHandler::class);

        // Media Pipeline
        $this->app->singleton(\App\Services\Media\FFmpegService::class);
        $this->app->singleton(\App\Services\Media\ImageService::class);
        $this->app->singleton(\App\Services\Media\OCRService::class);
        $this->app->singleton(\App\Services\Media\MediaPipelineService::class);

        // Security
        $this->app->singleton(\App\Services\Security\ClamAVService::class);
        $this->app->singleton(\App\Services\Security\WebhookAuthService::class);

        // Knowledge
        $this->app->singleton(\App\Services\Knowledge\VectorStoreService::class);
        $this->app->singleton(\App\Services\Knowledge\ContextGraphService::class);

        // Workflows
        $this->app->singleton(\App\Workflows\WorkflowDispatcher::class);
        $this->app->singleton(\App\Workflows\WorkflowStateMachine::class);
        $this->app->singleton(\App\Workflows\WorkflowTaskRunner::class);

        // Skills
        $this->app->singleton(\App\Services\Skills\SkillExecutorService::class);

        // Marketing
        $this->app->singleton(\App\Services\Marketing\CampaignService::class);

        // Growth
        $this->app->singleton(\App\Services\Growth\ExperimentationEngine::class);

        // Supervisor
        $this->app->singleton(\App\Services\Supervisor\SupervisorService::class);

        // Agents
        $this->app->singleton(\App\Agents\AgentOrchestrator::class);

        // Skill classes
        $this->app->bind(\App\Skills\ImageEnhanceSkill::class);
        $this->app->bind(\App\Skills\VideoExtractFramesSkill::class);
        $this->app->bind(\App\Skills\BackgroundRemoveSkill::class);
        $this->app->bind(\App\Skills\OcrExtractSkill::class);
        $this->app->bind(\App\Skills\LlmGenerateTextSkill::class);
        $this->app->bind(\App\Skills\ResumeParseSkill::class);
        $this->app->bind(\App\Skills\CandidateScoreSkill::class);
        $this->app->bind(\App\Skills\JobPostPublishSkill::class);
    }

    public function boot(): void
    {
        // Warn on missing/placeholder required environment variables
        if (app()->environment('production', 'staging')) {
            $required = [
                'APP_KEY', 'DB_PASSWORD',
                'OPENAI_API_KEY', 'ANTHROPIC_API_KEY',
                'TELEGRAM_BOT_TOKEN', 'TELEGRAM_WEBHOOK_SECRET',
                'TELEGRAM_ALLOWED_USERS', 'TELEGRAM_ADMIN_CHAT_ID',
                'DASHBOARD_PASSWORD',
            ];
            foreach ($required as $key) {
                $val = env($key, '');
                if (empty($val) || str_contains($val, 'CHANGE_ME')) {
                    Log::critical("Required env var {$key} is missing or placeholder. Platform will not function correctly.");
                }
            }
        }

        foreach ([
            storage_path('app/temp'),
            storage_path('framework/cache/data'),
            storage_path('framework/sessions'),
            storage_path('framework/testing'),
            storage_path('framework/views'),
            storage_path('logs'),
        ] as $directory) {
            if (! is_dir($directory)) {
                @mkdir($directory, 0755, true);
            }
        }
    }
}
