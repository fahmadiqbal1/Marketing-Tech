<?php

namespace App\Jobs;

use App\Models\SystemEvent;
use App\Services\AI\AIRouter;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Processes a hiring/job-post request from Telegram.
 *
 * Flow:
 *   1. Extract structured brief via gpt-4o-mini (title, dept, level, etc.)
 *   2. Generate full 400-600 word JD via claude-haiku
 *   3. Store draft in Redis with 24h TTL
 *   4. Send preview + approval buttons to Telegram
 */
class ProcessHiringRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $queue   = 'agents';
    public int    $tries   = 2;
    public int    $timeout = 180;
    public array  $backoff = [30, 60];

    public function __construct(
        private readonly int    $chatId,
        private readonly string $instruction,
    ) {}

    public function handle(AIRouter $ai, TelegramBotService $telegram): void
    {
        Log::info('ProcessHiringRequest: starting', ['chat_id' => $this->chatId]);

        // Step 1: Extract structured brief
        $briefPrompt = <<<PROMPT
Analyse this hiring request and return ONLY a JSON object with no preamble:
Request: "{$this->instruction}"
{
  "title": "exact job title",
  "department": "e.g. Medical, Engineering, Marketing",
  "level": "junior|mid|senior|lead|director",
  "location": "e.g. Karachi (default if not mentioned)",
  "employment_type": "full_time|part_time|contract|freelance",
  "requirements": ["req1", "req2"],
  "nice_to_have": [],
  "salary_range": null
}
PROMPT;

        $briefRaw = $ai->complete($briefPrompt, 'gpt-4o-mini', 400, 0.0);
        $brief    = json_decode(trim($briefRaw), true) ?? [
            'title'           => 'Position',
            'department'      => 'General',
            'level'           => 'mid',
            'location'        => 'Karachi',
            'employment_type' => 'full_time',
            'requirements'    => [],
            'nice_to_have'    => [],
            'salary_range'    => null,
        ];

        // Step 2: Generate full job description
        $jdPrompt = "Write a compelling 400-600 word job description for: {$brief['title']}, "
            . "{$brief['level']} level, {$brief['department']} department. "
            . "Location: {$brief['location']}. "
            . "Requirements: " . implode(', ', $brief['requirements'] ?? []) . ". "
            . "Cover: role overview, key responsibilities, what success looks like, and culture fit.";

        $jd = $ai->complete($jdPrompt, 'claude-haiku-4-5-20251001', 900, 0.6);

        // Step 3: Store in Redis (24h TTL)
        $jobPostId = (string) Str::uuid();
        Cache::put("pending_job_post:{$jobPostId}", [
            'brief'                => $brief,
            'description'          => $jd,
            'chat_id'              => $this->chatId,
            'original_instruction' => $this->instruction,
        ], now()->addHours(24));

        // Step 4: Send preview + action buttons
        $preview = "📋 *Job Post Draft — {$brief['title']}*\n\n"
            . mb_substr($jd, 0, 900)
            . (mb_strlen($jd) > 900 ? '...' : '')
            . "\n\n_📍 {$brief['location']} · {$brief['level']} · {$brief['employment_type']}_";

        $telegram->sendMessage($this->chatId, $preview);
        $telegram->sendMessage(
            $this->chatId,
            "Ready to publish this job post?",
            replyMarkup: $telegram->inlineKeyboard([
                [['text' => '✅ Publish Job Post', 'callback_data' => "jobpost:approve:{$jobPostId}"]],
                [['text' => '🔄 Regenerate',        'callback_data' => "jobpost:regen:{$jobPostId}"]],
                [['text' => '✏️ Edit Details',       'callback_data' => "jobpost:edit:{$jobPostId}"]],
                [['text' => '❌ Cancel',             'callback_data' => "jobpost:cancel:{$jobPostId}"]],
            ])
        );

        Log::info('ProcessHiringRequest: preview sent', [
            'job_post_id' => $jobPostId,
            'title'       => $brief['title'],
            'chat_id'     => $this->chatId,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ProcessHiringRequest: all retries exhausted', [
            'chat_id' => $this->chatId,
            'error'   => $e->getMessage(),
        ]);
        try {
            app(TelegramBotService::class)->sendMessage(
                $this->chatId,
                '⚠️ *Job post generation failed.* Please try again or type your request differently.'
            );
        } catch (\Throwable) {}
    }
}
