<?php

namespace App\Http\Controllers;

use App\Services\ApiCredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class SettingsController extends Controller
{
    /** Fields that ARE API secrets → stored in DB (never written to .env) */
    private const SECRET_FIELDS = [
        'OPENAI_API_KEY',
        'ANTHROPIC_API_KEY',
        'GEMINI_API_KEY',
        'TELEGRAM_BOT_TOKEN',
        'TELEGRAM_WEBHOOK_SECRET',
    ];

    /** Provider mapping for secrets */
    private const PROVIDER_MAP = [
        'OPENAI_API_KEY' => 'openai',
        'ANTHROPIC_API_KEY' => 'anthropic',
        'GEMINI_API_KEY' => 'gemini',
        'TELEGRAM_BOT_TOKEN' => 'telegram',
        'TELEGRAM_WEBHOOK_SECRET' => 'telegram',
    ];

    private string $envPath;

    public function __construct(private readonly ApiCredentialService $credentials)
    {
        $this->envPath = base_path('.env');
    }

    public function index()
    {
        return view('settings');
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'APP_URL' => env('APP_URL', ''),
            'APP_DEBUG' => env('APP_DEBUG', false),
            'APP_ENV' => env('APP_ENV', 'local'),
            'OPENAI_API_KEY' => $this->maskOrEmpty('OPENAI_API_KEY'),
            'ANTHROPIC_API_KEY' => $this->maskOrEmpty('ANTHROPIC_API_KEY'),
            'GEMINI_API_KEY' => $this->maskOrEmpty('GEMINI_API_KEY'),
            'TELEGRAM_BOT_TOKEN' => $this->maskOrEmpty('TELEGRAM_BOT_TOKEN'),
            'TELEGRAM_WEBHOOK_SECRET' => $this->maskOrEmpty('TELEGRAM_WEBHOOK_SECRET'),
            'TELEGRAM_ALLOWED_USERS' => env('TELEGRAM_ALLOWED_USERS', ''),
            'TELEGRAM_ADMIN_CHAT_ID' => env('TELEGRAM_ADMIN_CHAT_ID', ''),
            'DB_CONNECTION' => env('DB_CONNECTION', 'mysql'),
            'DB_HOST' => env('DB_HOST', ''),
            'DB_PORT' => env('DB_PORT', '3306'),
            'DB_DATABASE' => env('DB_DATABASE', ''),
            'DB_USERNAME' => env('DB_USERNAME', ''),
            'QUEUE_CONNECTION' => env('QUEUE_CONNECTION', 'database'),
            'CACHE_STORE' => env('CACHE_STORE', env('CACHE_DRIVER', 'file')),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $secretFields = self::SECRET_FIELDS;
        $nonSecretAllowed = [
            'APP_URL', 'APP_DEBUG', 'APP_ENV',
            'TELEGRAM_ALLOWED_USERS', 'TELEGRAM_ADMIN_CHAT_ID',
            'QUEUE_CONNECTION', 'CACHE_STORE',
        ];

        $data = $request->only(array_merge($secretFields, $nonSecretAllowed));

        if (empty($data)) {
            return response()->json(['error' => 'No valid fields provided'], 422);
        }

        $warnings = [];

        foreach ($secretFields as $field) {
            if (! isset($data[$field]) || str_contains((string) $data[$field], '****')) {
                unset($data[$field]);

                continue;
            }

            try {
                $provider = self::PROVIDER_MAP[$field] ?? 'other';
                $this->credentials->store($provider, $field, (string) $data[$field]);
            } catch (\Throwable $e) {
                $warnings[] = "Unable to store {$field} in the database: {$e->getMessage()}";
                Log::warning('Settings secret save failed', ['field' => $field, 'error' => $e->getMessage()]);
            }

            unset($data[$field]);
        }

        if (! empty($data) && file_exists($this->envPath)) {
            $env = file_get_contents($this->envPath);

            foreach ($data as $key => $value) {
                if (str_contains((string) $value, '****')) {
                    continue;
                }

                $escaped = $this->escapeEnvValue((string) $value);

                if (preg_match("/^{$key}=/m", $env)) {
                    $env = preg_replace("/^{$key}=.*/m", "{$key}={$escaped}", $env);
                } else {
                    $env .= "\n{$key}={$escaped}";
                }
            }

            file_put_contents($this->envPath, $env);
        }

        try {
            Artisan::call('config:clear');
        } catch (\Throwable $e) {
            $warnings[] = 'Configuration was saved, but the config cache could not be cleared.';
            Log::warning('config:clear failed after settings update', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'saved' => true,
            'warnings' => $warnings,
        ]);
    }

    public function registerWebhook(): JsonResponse
    {
        // Validate prerequisites before calling the Artisan command
        $botToken = $this->credentials->retrieve('TELEGRAM_BOT_TOKEN');
        if (empty($botToken)) {
            return response()->json([
                'success' => false,
                'output' => 'Telegram bot token is not configured. Add TELEGRAM_BOT_TOKEN in Settings first.',
            ], 422);
        }

        $appUrl = env('APP_URL', '');
        if (empty($appUrl) || $appUrl === 'http://localhost') {
            return response()->json([
                'success' => false,
                'output' => 'APP_URL is not set to a public URL. Update APP_URL in Application Settings first.',
            ], 422);
        }

        try {
            $exit = Artisan::call('telegram:webhook');
            $output = Artisan::output();

            if ($exit !== 0) {
                Log::error('Webhook registration failed', [
                    'exit_code' => $exit,
                    'output' => trim($output),
                    'app_url' => $appUrl,
                ]);
            }

            return response()->json([
                'success' => $exit === 0,
                'output' => trim($output),
            ]);
        } catch (\Throwable $e) {
            Log::error('Webhook registration exception', [
                'error' => $e->getMessage(),
                'app_url' => $appUrl,
            ]);

            return response()->json(['success' => false, 'output' => $e->getMessage()], 422);
        }
    }

    private function maskOrEmpty(string $envKey): string
    {
        $value = $this->credentials->retrieve($envKey) ?? '';

        return $this->mask($value);
    }

    private function mask(string $value): string
    {
        if (empty($value) || $value === 'null') {
            return '';
        }
        $len = strlen($value);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }

        return substr($value, 0, 4).str_repeat('*', $len - 8).substr($value, -4);
    }

    private function escapeEnvValue(string $value): string
    {
        if (preg_match('/\s/', $value) || str_contains($value, '"')) {
            return '"'.addslashes($value).'"';
        }

        return $value ?: 'null';
    }
}
