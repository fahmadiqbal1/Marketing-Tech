<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Unified media generation service.
 *
 * Wraps existing ImageService / FFmpeg operations and adds AI image generation
 * via OpenAI DALL-E (when API key is configured). All methods have safe fallbacks
 * so the marketing system functions even without media credentials.
 */
class MediaGenerationService
{
    /** Platform-specific image dimensions */
    private const PLATFORM_SIZES = [
        'instagram' => '1080x1080',
        'linkedin'  => '1200x627',
        'tiktok'    => '1080x1920',
        'twitter'   => '1600x900',
        'default'   => '1024x1024',
    ];

    public function __construct(
        private readonly ImageService $imageService,
    ) {}

    /**
     * Generate an image from a text prompt using DALL-E.
     * Falls back to a placeholder descriptor if no API key is configured.
     *
     * @return array{storage_key: string|null, prompt: string, size: string, source: string, success: bool}
     */
    public function generateImage(string $prompt, array $options = []): array
    {
        $size    = $options['size']    ?? '1024x1024';
        $quality = $options['quality'] ?? 'standard';
        $model   = $options['model']   ?? 'dall-e-3';

        $apiKey = config('services.openai.key') ?? env('OPENAI_API_KEY');

        if (empty($apiKey) || str_contains($apiKey, 'CHANGE_ME')) {
            Log::info("[MediaGenerationService] No OpenAI key — returning visual brief only.");
            return [
                'storage_key' => null,
                'prompt'      => $prompt,
                'size'        => $size,
                'source'      => 'brief_only',
                'success'     => true,
                'note'        => 'Configure OPENAI_API_KEY to enable actual image generation.',
            ];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type'  => 'application/json',
            ])->timeout(60)->post('https://api.openai.com/v1/images/generations', [
                'model'   => $model,
                'prompt'  => $prompt,
                'n'       => 1,
                'size'    => $size,
                'quality' => $quality,
            ]);

            if ($response->failed()) {
                throw new \RuntimeException("DALL-E API error: " . $response->body());
            }

            $imageUrl = $response->json('data.0.url');

            // Download and store in MinIO/local storage
            $imageContent = Http::get($imageUrl)->body();
            $storageKey   = 'generated/' . Str::uuid() . '.png';

            try {
                Storage::disk('minio')->put($storageKey, $imageContent);
            } catch (\Throwable) {
                // MinIO may not be configured — fall back to local storage
                Storage::put($storageKey, $imageContent);
            }

            return [
                'storage_key' => $storageKey,
                'prompt'      => $prompt,
                'size'        => $size,
                'source'      => 'dall-e',
                'success'     => true,
            ];
        } catch (\Throwable $e) {
            Log::error("[MediaGenerationService] generateImage failed: " . $e->getMessage());
            return [
                'storage_key' => null,
                'prompt'      => $prompt,
                'size'        => $size,
                'source'      => 'failed',
                'success'     => false,
                'error'       => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate a platform-sized ad creative image.
     * Automatically picks the correct dimensions for the target platform.
     */
    public function generateAdCreative(string $prompt, string $platform = 'default'): array
    {
        $size = self::PLATFORM_SIZES[strtolower($platform)] ?? self::PLATFORM_SIZES['default'];

        // DALL-E only supports specific sizes; map to nearest supported
        $dalleSize = match (true) {
            str_starts_with($size, '1080x1080') => '1024x1024',
            str_starts_with($size, '1200x627')  => '1792x1024',
            str_starts_with($size, '1080x1920') => '1024x1792',
            str_starts_with($size, '1600x900')  => '1792x1024',
            default                             => '1024x1024',
        };

        $platformPrompt = "{$prompt}. Style: professional marketing creative for {$platform}. No text overlay.";

        return $this->generateImage($platformPrompt, ['size' => $dalleSize]);
    }

    /**
     * Generate a video concept / script. Actual video rendering requires FFmpeg + assets.
     * Returns a structured brief — stub for future integration.
     */
    public function generateVideo(string $prompt, array $options = []): array
    {
        Log::info("[MediaGenerationService] Video generation requested — returning script brief.", ['prompt' => $prompt]);

        return [
            'storage_key' => null,
            'prompt'      => $prompt,
            'source'      => 'script_brief',
            'success'     => true,
            'brief'       => [
                'script'        => $prompt,
                'duration_sec'  => $options['duration_sec'] ?? 30,
                'aspect_ratio'  => $options['aspect_ratio'] ?? '9:16',
                'style'         => $options['style'] ?? 'dynamic',
                'note'          => 'Connect a video generation API (RunwayML, Pika, Sora) to produce actual video.',
            ],
        ];
    }

    /**
     * Remove background from an existing image using ImageService.
     * Falls back gracefully if ImageService / ImageMagick is unavailable.
     */
    public function removeBackground(string $storageKey): array
    {
        try {
            $content  = Storage::disk('minio')->get($storageKey);
            $tempPath = storage_path('app/temp/' . Str::uuid() . '.png');
            @mkdir(dirname($tempPath), 0755, true);
            file_put_contents($tempPath, $content);

            $outputPath = $this->imageService->removeBackground($tempPath, ['fuzz' => 10, 'bg_color' => '#FFFFFF']);
            $outputKey  = 'processed/bg_removed_' . Str::uuid() . '.png';

            Storage::disk('minio')->put($outputKey, file_get_contents($outputPath));
            @unlink($tempPath);
            @unlink($outputPath);

            return ['storage_key' => $outputKey, 'source_key' => $storageKey, 'success' => true];
        } catch (\Throwable $e) {
            Log::error("[MediaGenerationService] removeBackground failed: " . $e->getMessage());
            return ['storage_key' => $storageKey, 'success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Enhance an existing image (sharpen, auto-levels) using ImageService.
     */
    public function enhanceImage(string $storageKey, array $options = []): array
    {
        try {
            $content  = Storage::disk('minio')->get($storageKey);
            $ext      = pathinfo($storageKey, PATHINFO_EXTENSION) ?: 'jpg';
            $tempPath = storage_path('app/temp/' . Str::uuid() . '.' . $ext);
            @mkdir(dirname($tempPath), 0755, true);
            file_put_contents($tempPath, $content);

            $outputPath = $this->imageService->enhance($tempPath, array_merge([
                'sharpen'     => true,
                'denoise'     => false,
                'auto_levels' => true,
                'quality'     => 85,
            ], $options));

            $outputKey = 'enhanced/' . Str::uuid() . '.' . $ext;
            Storage::disk('minio')->put($outputKey, file_get_contents($outputPath));
            @unlink($tempPath);
            @unlink($outputPath);

            return ['storage_key' => $outputKey, 'source_key' => $storageKey, 'success' => true];
        } catch (\Throwable $e) {
            Log::error("[MediaGenerationService] enhanceImage failed: " . $e->getMessage());
            return ['storage_key' => $storageKey, 'success' => false, 'error' => $e->getMessage()];
        }
    }
}
