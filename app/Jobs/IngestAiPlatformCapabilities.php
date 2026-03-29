<?php

namespace App\Jobs;

use App\Models\CustomAiPlatform;
use App\Models\KnowledgeBase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IngestAiPlatformCapabilities implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int    $tries   = 2;
    public int    $timeout = 60;
    public string $queue   = 'low';

    public function __construct(private readonly string $platformId) {}

    public function handle(): void
    {
        $platform = CustomAiPlatform::find($this->platformId);
        if (! $platform) {
            return;
        }

        $apiKey = env($platform->api_key_env, '');
        if (empty($apiKey)) {
            Log::info('IngestAiPlatformCapabilities: no API key set, skipping', ['platform' => $platform->name]);
            return;
        }

        $modelsUrl = rtrim($platform->api_base_url, '/') . '/models';

        try {
            $request = Http::timeout(15);

            if ($platform->auth_type === 'bearer') {
                $request = $request->withToken($apiKey);
            } else {
                $headerName = $platform->auth_header ?: 'X-API-Key';
                $request    = $request->withHeaders([$headerName => $apiKey]);
            }

            $response = $request->get($modelsUrl);

            if (! $response->ok()) {
                Log::warning('IngestAiPlatformCapabilities: /models returned non-200', [
                    'platform' => $platform->name,
                    'status'   => $response->status(),
                ]);
                return;
            }

            // Handle both {"data": [...]} (OpenAI-compatible) and flat array responses
            $models = $response->json('data', $response->json('models', $response->json(null, [])));
            if (! is_array($models)) {
                return;
            }

            $count = 0;
            foreach ($models as $model) {
                $modelId = is_array($model) ? ($model['id'] ?? null) : $model;
                if (! $modelId) {
                    continue;
                }

                $title = "AI Model: {$platform->name} — {$modelId}";
                KnowledgeBase::firstOrCreate(
                    ['title' => $title],
                    [
                        'content'  => "Model '{$modelId}' available via {$platform->name} ({$platform->api_base_url}).",
                        'category' => 'agent-skills',
                        'tags'     => ['ai-model', $platform->name, $modelId],
                    ]
                );
                $count++;
            }

            Log::info('IngestAiPlatformCapabilities: ingested', [
                'platform' => $platform->name,
                'models'   => $count,
            ]);
        } catch (\Throwable $e) {
            Log::warning('IngestAiPlatformCapabilities: failed', [
                'platform' => $platform->name,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('IngestAiPlatformCapabilities permanently failed', [
            'platform_id' => $this->platformId,
            'error'       => $e->getMessage(),
        ]);
    }
}
