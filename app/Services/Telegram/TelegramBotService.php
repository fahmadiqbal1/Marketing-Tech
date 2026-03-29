<?php

namespace App\Services\Telegram;

use App\Agents\AgentOrchestrator;
use App\Jobs\ProcessCampaignRequest;
use App\Jobs\ProcessHiringRequest;
use App\Services\AI\OpenAIService;
use App\Services\Campaign\CampaignApprovalService;
use App\Services\Hiring\HiringApprovalService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TelegramBotService
{
    private string $token;
    private string $apiBase;
    private array  $allowedUsers;

    public function __construct(
        private readonly AgentOrchestrator       $orchestrator,
        private readonly CommandHandler          $commandHandler,
        private readonly OpenAIService           $openai,
        private readonly CampaignApprovalService $approvalService,
        private readonly HiringApprovalService   $hiringApproval,
    ) {
        $this->token       = config('agents.telegram.token');
        $this->apiBase     = "https://api.telegram.org/bot{$this->token}";
        $this->allowedUsers = config('agents.telegram.allowed_users', []);
    }

    /**
     * Route a Telegram update to the appropriate handler.
     */
    public function handleUpdate(array $update): void
    {
        $message  = $update['message'] ?? $update['edited_message'] ?? null;
        $callback = $update['callback_query'] ?? null;

        if ($callback) {
            $this->handleCallbackQuery($callback);
            return;
        }

        if (! $message) {
            return;
        }

        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];
        $text   = $message['text'] ?? '';

        // Enforce allowlist
        if (! $this->isAuthorised($userId)) {
            $this->sendMessage($chatId, '⛔ Access denied. Your user ID is not authorised.');
            Log::warning('Unauthorised Telegram access attempt', ['user_id' => $userId]);
            return;
        }

        // Voice note → transcribe → hiring or campaign pipeline
        if (! empty($message['voice'])) {
            $transcript = $this->transcribeVoice($message['voice'], $chatId);
            if ($transcript) {
                if ($this->isHiringIntent($transcript)) {
                    $this->dispatchHiringRequest($chatId, $transcript);
                } else {
                    $this->dispatchCampaignRequest($chatId, $transcript, null, 'none');
                }
            }
            return;
        }

        // Audio document (mp3/ogg via document)
        if (! empty($message['document']) && str_starts_with($message['document']['mime_type'] ?? '', 'audio/')) {
            $transcript = $this->transcribeVoice($message['document'], $chatId);
            if ($transcript) {
                if ($this->isHiringIntent($transcript)) {
                    $this->dispatchHiringRequest($chatId, $transcript);
                } else {
                    $this->dispatchCampaignRequest($chatId, $transcript, null, 'none');
                }
            }
            return;
        }

        // Photo with caption → campaign pipeline
        if (! empty($message['photo'])) {
            $caption  = $message['caption'] ?? 'Create a campaign from this image';
            $fileId   = end($message['photo'])['file_id'];
            $mediaKey = $this->downloadTelegramMedia($fileId, 'jpg');
            $this->dispatchCampaignRequest($chatId, $caption, $mediaKey, 'image');
            return;
        }

        // Video with caption → campaign pipeline
        if (! empty($message['video'])) {
            $caption  = $message['caption'] ?? 'Create a campaign from this video';
            $mediaKey = $this->downloadTelegramMedia($message['video']['file_id'], 'mp4');
            $this->dispatchCampaignRequest($chatId, $caption, $mediaKey, 'video');
            return;
        }

        // Document / photo upload (legacy non-campaign handler)
        if (! empty($message['document'])) {
            $this->handleFileUpload($message, $chatId, $userId);
            return;
        }

        if (empty($text)) {
            return;
        }

        // Route commands vs free-form messages
        if (str_starts_with($text, '/')) {
            $this->commandHandler->handle($text, $message, $chatId, $userId);
        } else {
            // Free-form text → check if it looks like a campaign request, else fall through to agent
            $this->handleFreeText($text, $message, $chatId, $userId);
        }
    }

    /**
     * Handle inline keyboard callback queries.
     */
    private function handleCallbackQuery(array $callback): void
    {
        $chatId    = $callback['message']['chat']['id'];
        $userId    = $callback['from']['id'];
        $data      = $callback['data'] ?? '';
        $messageId = $callback['message']['message_id'];

        if (! $this->isAuthorised($userId)) {
            return;
        }

        // Answer the callback to remove loading spinner
        $this->answerCallbackQuery($callback['id']);

        // Hiring approval callbacks: jobpost:action:uuid
        if (str_starts_with($data, 'jobpost:')) {
            [$_p, $action, $jobPostId] = array_pad(explode(':', $data, 3), 3, '');
            match ($action) {
                'approve' => $this->hiringApproval->approve($jobPostId, $chatId, $messageId),
                'regen'   => $this->hiringApproval->regenerate($jobPostId, $chatId, $messageId),
                'edit'    => $this->hiringApproval->requestEdit($jobPostId, $chatId, $messageId),
                'cancel'  => $this->editMessage($chatId, $messageId, '❌ Job post cancelled.'),
                default   => $this->sendMessage($chatId, 'Unknown hiring action.'),
            };
            return;
        }

        // Campaign approval callbacks use campaign:action:uuid format
        if (str_starts_with($data, 'campaign:')) {
            $parts      = explode(':', $data, 3);
            $action     = $parts[1] ?? '';
            $campaignId = $parts[2] ?? '';

            match ($action) {
                'approve' => $this->approvalService->approve($campaignId, $chatId, $messageId),
                'regen'   => $this->approvalService->regenerate($campaignId, $chatId, $messageId),
                'edit'    => $this->approvalService->requestEdit($campaignId, $chatId, $messageId),
                'cancel'  => $this->editMessage($chatId, $messageId, '❌ Campaign cancelled.'),
                default   => $this->sendMessage($chatId, 'Unknown campaign action.'),
            };
            return;
        }

        // Parse action:payload format (legacy callbacks)
        [$action, $payload] = array_pad(explode(':', $data, 2), 2, '');

        match ($action) {
            'cancel_job'    => $this->commandHandler->cancelJob($payload, $chatId),
            'confirm_run'   => $this->commandHandler->confirmAndRun($payload, $chatId, $userId),
            'view_result'   => $this->commandHandler->viewResult($payload, $chatId),
            default         => $this->sendMessage($chatId, 'Unknown action.'),
        };
    }

    /**
     * Handle uploaded files via Telegram.
     */
    private function handleFileUpload(array $message, int $chatId, int $userId): void
    {
        $this->sendTypingIndicator($chatId);
        $this->sendMessage($chatId, '📥 File received. Downloading and queuing for processing...');

        $file = $message['document'] ?? null;
        if (! $file && ! empty($message['photo'])) {
            // Telegram sends multiple photo sizes; take the largest
            $file = end($message['photo']);
        }

        if (! $file) {
            return;
        }

        $fileId   = $file['file_id'];
        $fileName = $file['file_name'] ?? "upload_{$fileId}";
        $mimeType = $file['mime_type'] ?? 'application/octet-stream';

        // Download from Telegram and pass to media agent
        $this->orchestrator->handleFileUpload(
            chatId:   $chatId,
            userId:   $userId,
            fileId:   $fileId,
            fileName: $fileName,
            mimeType: $mimeType,
            caption:  $message['caption'] ?? null,
        );
    }

    /**
     * Free-form text → hiring pipeline or agent orchestrator.
     */
    private function handleFreeText(string $text, array $message, int $chatId, int $userId): void
    {
        $this->sendTypingIndicator($chatId);

        if ($this->isHiringIntent($text)) {
            $this->dispatchHiringRequest($chatId, $text);
            return;
        }

        $this->orchestrator->dispatchFromTelegram(
            instruction: $text,
            chatId:      $chatId,
            userId:      $userId,
            messageId:   $message['message_id'],
        );
    }

    /**
     * Detect hiring/job-post intent from text without an API call.
     */
    private function isHiringIntent(string $text): bool
    {
        $lower = strtolower($text);
        foreach ([
            'hire ', 'hiring', 'recruit', 'job post', 'job opening', 'vacancy',
            'open position', 'post a job', 'post an ad', 'need to hire',
            'we need a ', 'looking for a ', 'seeking a ',
        ] as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Dispatch a ProcessHiringRequest job and send an acknowledgement.
     */
    private function dispatchHiringRequest(int $chatId, string $instruction): void
    {
        $this->sendTypingIndicator($chatId);
        $this->sendMessage($chatId,
            "📋 *Preparing job post draft...*\n"
            . "I'll have a full description ready for your review shortly.");

        ProcessHiringRequest::dispatch($chatId, $instruction)->onQueue('agents');
    }

    // ── Telegram API methods ──────────────────────────────────────

    public function sendMessage(
        int    $chatId,
        string $text,
        array  $replyMarkup = [],
        string $parseMode   = 'Markdown',
    ): array {
        $payload = [
            'chat_id'    => $chatId,
            'text'       => mb_substr($text, 0, 4096),
            'parse_mode' => $parseMode,
        ];

        if ($replyMarkup) {
            $payload['reply_markup'] = json_encode($replyMarkup);
        }

        return $this->apiCall('sendMessage', $payload);
    }

    public function editMessage(int $chatId, int $messageId, string $text, array $replyMarkup = []): array
    {
        $payload = [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => mb_substr($text, 0, 4096),
            'parse_mode' => 'Markdown',
        ];

        if ($replyMarkup) {
            $payload['reply_markup'] = json_encode($replyMarkup);
        }

        return $this->apiCall('editMessageText', $payload);
    }

    public function sendDocument(int $chatId, string $filePath, string $caption = ''): array
    {
        return Http::attach('document', file_get_contents($filePath), basename($filePath))
            ->post("{$this->apiBase}/sendDocument", [
                'chat_id' => $chatId,
                'caption' => mb_substr($caption, 0, 1024),
            ])
            ->json();
    }

    public function sendPhoto(int $chatId, string $filePath, string $caption = ''): array
    {
        return Http::attach('photo', file_get_contents($filePath), basename($filePath))
            ->post("{$this->apiBase}/sendPhoto", [
                'chat_id' => $chatId,
                'caption' => mb_substr($caption, 0, 1024),
            ])
            ->json();
    }

    public function getFileInfo(string $fileId): ?array
    {
        $result = $this->apiCall('getFile', ['file_id' => $fileId]);
        return $result['ok'] ? $result['result'] : null;
    }

    public function downloadFile(string $filePath): ?string
    {
        $url      = "https://api.telegram.org/file/bot{$this->token}/{$filePath}";
        $response = Http::timeout(60)->get($url);

        if ($response->successful()) {
            return $response->body();
        }

        return null;
    }

    public function sendTypingIndicator(int $chatId): void
    {
        $this->apiCall('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = ''): void
    {
        $this->apiCall('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text'              => $text,
        ]);
    }

    public function registerWebhook(string $url, string $secretToken): array
    {
        return $this->apiCall('setWebhook', [
            'url'             => $url,
            'secret_token'    => $secretToken,
            'allowed_updates' => ['message', 'edited_message', 'callback_query'],
            'max_connections' => 100,
            'drop_pending_updates' => true,
        ]);
    }

    public function getWebhookInfo(): array
    {
        return $this->apiCall('getWebhookInfo', []);
    }

    /**
     * Build an inline keyboard markup.
     */
    public function inlineKeyboard(array $buttons): array
    {
        return ['inline_keyboard' => $buttons];
    }

    // ── Campaign media helpers ────────────────────────────────────

    /**
     * Transcribe a voice/audio message using Whisper.
     * Returns the transcript string, or null on failure.
     */
    private function transcribeVoice(array $voiceOrDoc, int $chatId): ?string
    {
        $fileId = $voiceOrDoc['file_id'];

        $fileInfo = $this->apiCall('getFile', ['file_id' => $fileId]);
        $filePath = $fileInfo['result']['file_path'] ?? null;
        if (!$filePath) {
            $this->sendMessage($chatId, '⚠️ Could not access voice file. Please try again.');
            return null;
        }

        $content  = $this->downloadFile($filePath);
        if (!$content) {
            $this->sendMessage($chatId, '⚠️ Could not download voice file. Please try again.');
            return null;
        }

        $tempPath = storage_path('app/temp/' . Str::uuid() . '.ogg');
        @mkdir(dirname($tempPath), 0755, true);
        file_put_contents($tempPath, $content);

        try {
            $transcript = $this->openai->transcribe($tempPath);
            @unlink($tempPath);

            if (empty(trim($transcript))) {
                $this->sendMessage($chatId, '⚠️ Could not understand the voice note. Please try again or type your request.');
                return null;
            }

            Log::info('TelegramBotService: voice transcribed', [
                'chat_id' => $chatId,
                'length'  => strlen($transcript),
            ]);
            return $transcript;
        } catch (\Throwable $e) {
            @unlink($tempPath);
            Log::error('TelegramBotService: voice transcription failed', ['error' => $e->getMessage()]);
            $this->sendMessage($chatId, '⚠️ Voice transcription failed. Please type your request instead.');
            return null;
        }
    }

    /**
     * Download a Telegram media file and store in MinIO.
     * Returns the MinIO storage key.
     */
    private function downloadTelegramMedia(string $fileId, string $ext): ?string
    {
        $fileInfo = $this->apiCall('getFile', ['file_id' => $fileId]);
        $filePath = $fileInfo['result']['file_path'] ?? null;
        if (!$filePath) return null;

        $content = $this->downloadFile($filePath);
        if (!$content) return null;

        $key = 'uploads/' . Str::uuid() . '.' . $ext;
        try {
            Storage::disk('minio')->put($key, $content);
            return $key;
        } catch (\Throwable $e) {
            Log::error('TelegramBotService: MinIO upload failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Dispatch a ProcessCampaignRequest job and send an acknowledgement message.
     */
    private function dispatchCampaignRequest(
        int     $chatId,
        string  $instruction,
        ?string $mediaKey,
        string  $mediaType,
    ): void {
        $this->sendTypingIndicator($chatId);
        $this->sendMessage($chatId,
            '🎯 *Analysing your request...*' . "\n"
            . "I'll prepare campaign drafts for all platforms shortly.");

        ProcessCampaignRequest::dispatch($chatId, $instruction, $mediaKey, $mediaType)
            ->onQueue('agents');
    }

    // ── Private helpers ───────────────────────────────────────────

    private function isAuthorised(int $userId): bool
    {
        return empty($this->allowedUsers) || in_array($userId, $this->allowedUsers, true);
    }

    public function apiCall(string $method, array $payload): array
    {
        try {
            $response = Http::timeout(30)
                ->retry(3, 500)
                ->post("{$this->apiBase}/{$method}", $payload);

            if ($response->failed()) {
                Log::error("Telegram API error [{$method}]", [
                    'status'   => $response->status(),
                    'response' => $response->json(),
                ]);
            }

            return $response->json() ?? ['ok' => false];
        } catch (\Throwable $e) {
            Log::error("Telegram API exception [{$method}]", ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
