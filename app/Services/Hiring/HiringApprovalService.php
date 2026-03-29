<?php

namespace App\Services\Hiring;

use App\Jobs\ProcessHiringRequest;
use App\Models\JobPosting;
use App\Models\SystemEvent;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Handles inline button callbacks for pending job post drafts.
 * approve → creates JobPosting entry (status=active)
 * regen   → re-dispatches ProcessHiringRequest
 * edit    → prompts user to send edits via Telegram
 * cancel  → edits message with cancellation notice
 */
class HiringApprovalService
{
    // No constructor — avoids circular dependency with TelegramBotService

    private function telegram(): TelegramBotService
    {
        return app(TelegramBotService::class);
    }

    public function approve(string $jobPostId, int $chatId, int $msgId): void
    {
        $pending = Cache::get("pending_job_post:{$jobPostId}");
        if (!$pending) {
            $this->telegram()->editMessage($chatId, $msgId,
                '⚠️ Draft expired (24h limit). Please resend your request.');
            return;
        }

        $brief = $pending['brief'];

        try {
            JobPosting::create([
                'title'           => $brief['title'],
                'department'      => $brief['department'],
                'location'        => $brief['location']        ?? 'Karachi',
                'employment_type' => $brief['employment_type'] ?? 'full_time',
                'level'           => $brief['level']           ?? 'mid',
                'description'     => $pending['description'],
                'requirements'    => $brief['requirements']    ?? [],
                'nice_to_have'    => $brief['nice_to_have']    ?? [],
                'salary_range'    => $brief['salary_range']    ?? null,
                'status'          => 'active',
                'metadata'        => ['auto_published' => false, 'source' => 'telegram_approval'],
            ]);
        } catch (\Throwable $e) {
            Log::error('HiringApprovalService: failed to create job posting', [
                'job_post_id' => $jobPostId,
                'error'       => $e->getMessage(),
            ]);
            $this->telegram()->editMessage($chatId, $msgId,
                '⚠️ Failed to save job post. Please try again.');
            return;
        }

        SystemEvent::emit(
            'job_posting_created',
            "Job posting '{$brief['title']}' published via Telegram",
            'info',
            'telegram'
        );

        Cache::forget("pending_job_post:{$jobPostId}");

        $this->telegram()->editMessage($chatId, $msgId,
            "✅ *Job post published!* '{$brief['title']}' is now live and accepting applications.");

        Log::info('HiringApprovalService: job posting approved', [
            'job_post_id' => $jobPostId,
            'title'       => $brief['title'],
        ]);
    }

    public function regenerate(string $jobPostId, int $chatId, int $msgId): void
    {
        $pending = Cache::get("pending_job_post:{$jobPostId}");
        if (!$pending) {
            $this->telegram()->editMessage($chatId, $msgId,
                '⚠️ Draft expired. Please resend your original request.');
            return;
        }

        $this->telegram()->editMessage($chatId, $msgId,
            '🔄 Regenerating job description with a fresh approach...');

        Cache::forget("pending_job_post:{$jobPostId}");

        ProcessHiringRequest::dispatch(
            $pending['chat_id'],
            $pending['original_instruction']
        )->onQueue('agents');
    }

    public function requestEdit(string $jobPostId, int $chatId, int $msgId): void
    {
        $this->telegram()->editMessage($chatId, $msgId,
            "✏️ *Edit mode* — Reply with your changes:\n\n"
            . "e.g. \"Make it senior level\" / \"Add Python as a requirement\" / \"Change location to Remote\"\n\n"
            . "_Your draft is saved for 24 hours._");
    }
}
