<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class SettingsController extends Controller
{
    private string $envPath;

    public function __construct()
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
            'APP_URL'                  => env('APP_URL', ''),
            'APP_DEBUG'                => env('APP_DEBUG', false),
            'APP_ENV'                  => env('APP_ENV', 'local'),
            'OPENAI_API_KEY'           => $this->mask(env('OPENAI_API_KEY', '')),
            'ANTHROPIC_API_KEY'        => $this->mask(env('ANTHROPIC_API_KEY', '')),
            'TELEGRAM_BOT_TOKEN'       => $this->mask(env('TELEGRAM_BOT_TOKEN', '')),
            'TELEGRAM_WEBHOOK_SECRET'  => $this->mask(env('TELEGRAM_WEBHOOK_SECRET', '')),
            'TELEGRAM_ALLOWED_USERS'   => env('TELEGRAM_ALLOWED_USERS', ''),
            'TELEGRAM_ADMIN_CHAT_ID'   => env('TELEGRAM_ADMIN_CHAT_ID', ''),
            'DB_HOST'                  => env('DB_HOST', ''),
            'DB_PORT'                  => env('DB_PORT', '5432'),
            'DB_DATABASE'              => env('DB_DATABASE', ''),
            'DB_USERNAME'              => env('DB_USERNAME', ''),
            'QUEUE_CONNECTION'         => env('QUEUE_CONNECTION', 'database'),
            'CACHE_DRIVER'             => env('CACHE_DRIVER', 'file'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $allowed = [
            'APP_URL', 'APP_DEBUG', 'APP_ENV',
            'OPENAI_API_KEY', 'ANTHROPIC_API_KEY',
            'TELEGRAM_BOT_TOKEN', 'TELEGRAM_WEBHOOK_SECRET',
            'TELEGRAM_ALLOWED_USERS', 'TELEGRAM_ADMIN_CHAT_ID',
        ];

        $data = $request->only($allowed);

        if (empty($data)) {
            return response()->json(['error' => 'No valid fields provided'], 422);
        }

        $env = file_get_contents($this->envPath);

        foreach ($data as $key => $value) {
            // Skip masked placeholders — user didn't change them
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

        try {
            Artisan::call('config:clear');
        } catch (\Throwable) {
            // non-fatal
        }

        return response()->json(['saved' => true]);
    }

    public function registerWebhook(): JsonResponse
    {
        try {
            $exit   = Artisan::call('telegram:webhook');
            $output = Artisan::output();
            return response()->json([
                'success' => $exit === 0,
                'output'  => trim($output),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'output' => $e->getMessage()], 500);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function mask(string $value): string
    {
        if (empty($value) || $value === 'null') {
            return '';
        }
        $len = strlen($value);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }
        return substr($value, 0, 4) . str_repeat('*', $len - 8) . substr($value, -4);
    }

    private function escapeEnvValue(string $value): string
    {
        if (preg_match('/\s/', $value) || str_contains($value, '"')) {
            return '"' . addslashes($value) . '"';
        }
        return $value ?: 'null';
    }
}
