<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramBotService;
use Illuminate\Console\Command;

class RegisterTelegramWebhookCommand extends Command
{
    protected $signature   = 'telegram:register-webhook';
    protected $description = 'Register or update the Telegram webhook URL';

    public function handle(TelegramBotService $bot): int
    {
        $url    = config('app.url') . '/api/webhook/telegram';
        $secret = config('agents.telegram.webhook_secret');

        $this->info("Registering webhook: {$url}");

        $result = $bot->registerWebhook($url, $secret);

        if ($result['ok'] ?? false) {
            $this->info('Webhook registered successfully.');
            $this->line('URL: ' . $url);
            return Command::SUCCESS;
        }

        $this->error('Webhook registration failed: ' . ($result['description'] ?? 'unknown error'));
        return Command::FAILURE;
    }
}
