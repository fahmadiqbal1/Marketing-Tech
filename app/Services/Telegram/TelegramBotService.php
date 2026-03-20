<?php

namespace App\Services\Telegram;

use App\Agents\AgentOrchestrator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class TelegramBotService
{
    private string $token;
    private string $apiBase;
    private array  $allowedUsers;

    public function __construct(
        private readonly AgentOrchestrator $orchestrator,
        private readonly CommandHandler    $commandHandler,
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

        // Handle document / photo uploads
        if (! empty($message['document']) || ! empty($message['photo'])) {
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
            // Free-form message — treat as agent instruction
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

        // Parse action:payload format
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
     * Free-form text → agent orchestrator.
     */
    private function handleFreeText(string $text, array $message, int $chatId, int $userId): void
    {
        $this->sendTypingIndicator($chatId);

        $this->orchestrator->dispatchFromTelegram(
            instruction: $text,
            chatId:      $chatId,
            userId:      $userId,
            messageId:   $message['message_id'],
        );
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
