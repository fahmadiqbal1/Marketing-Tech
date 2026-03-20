<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // AI Services
        $this->app->singleton(\App\Services\AI\OpenAIService::class);
        $this->app->singleton(\App\Services\AI\AnthropicService::class);
        $this->app->singleton(\App\Services\AI\AIRouter::class);

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
        // Ensure temp directory exists
        @mkdir(storage_path('app/temp'), 0755, true);
    }
}
