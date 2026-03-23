<?php

namespace App\Agents;

use App\Models\AgentJob;
use App\Services\AI\AnthropicService;
use App\Services\AI\GeminiService;
use App\Services\AI\OpenAIService;
use App\Services\ApiCredentialService;
use App\Services\CampaignContextService;
use App\Services\IterationEngineService;
use App\Services\Knowledge\VectorStoreService;
use App\Services\Media\FFmpegService;
use App\Services\Media\ImageService;
use App\Services\Media\OCRService;
use App\Services\Security\ClamAVService;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class MediaAgent extends BaseAgent
{
    protected string $agentType = 'media';

    public function __construct(
        OpenAIService          $openai,
        AnthropicService       $anthropic,
        GeminiService          $gemini,
        TelegramBotService     $telegram,
        VectorStoreService     $knowledge,
        ApiCredentialService   $credentials,
        IterationEngineService $iterationEngine,
        CampaignContextService $campaignContext,
        private readonly FFmpegService $ffmpeg,
        private readonly ImageService  $images,
        private readonly OCRService    $ocr,
        private readonly ClamAVService $clamav,
    ) {
        parent::__construct($openai, $anthropic, $gemini, $telegram, $knowledge, $credentials, $iterationEngine, $campaignContext);
    }

    protected function executeTool(string $name, array $args, AgentJob $job): mixed
    {
        return match ($name) {
            'transcode_video' => $this->toolTranscodeVideo($args, $job),
            'process_image'   => $this->toolProcessImage($args, $job),
            'extract_text'    => $this->toolExtractText($args),
            'scan_file'       => $this->toolScanFile($args),
            'store_media'     => $this->toolStoreMedia($args, $job),
            'get_media_info'  => $this->toolGetMediaInfo($args),
            'generate_thumbnail'  => $this->toolGenerateThumbnail($args, $job),
            'generate_image'      => $this->toolGenerateImage($args, $job),
            'generate_video'      => $this->toolGenerateVideo($args, $job),
            'remove_background'   => $this->toolRemoveBackground($args, $job),
            'enhance_image'       => $this->toolEnhanceImage($args, $job),
            default               => $this->toolResult(false, null, "Unknown tool: {$name}"),
        };
    }

    protected function getToolDefinitions(): array
    {
        return [
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'transcode_video',
                    'description' => 'Transcode a video file to a different format, resolution, or bitrate using FFmpeg',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'input_path'  => ['type' => 'string', 'description' => 'Path to input video in storage'],
                            'preset'      => ['type' => 'string', 'enum' => ['hd', 'sd', 'web', 'audio_only'], 'description' => 'Output quality preset'],
                            'format'      => ['type' => 'string', 'enum' => ['mp4', 'webm', 'mov', 'mp3'], 'description' => 'Output container format'],
                            'start_time'  => ['type' => 'string', 'description' => 'Optional trim start time HH:MM:SS'],
                            'duration'    => ['type' => 'string', 'description' => 'Optional trim duration HH:MM:SS'],
                        ],
                        'required'   => ['input_path', 'preset', 'format'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'process_image',
                    'description' => 'Resize, crop, convert, or optimise an image using ImageMagick',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'input_path' => ['type' => 'string'],
                            'operations' => [
                                'type'  => 'array',
                                'items' => [
                                    'type'       => 'object',
                                    'properties' => [
                                        'type'   => ['type' => 'string', 'enum' => ['resize', 'crop', 'convert', 'watermark', 'optimize', 'rotate']],
                                        'width'  => ['type' => 'integer'],
                                        'height' => ['type' => 'integer'],
                                        'format' => ['type' => 'string', 'enum' => ['jpg', 'png', 'webp', 'gif']],
                                        'quality' => ['type' => 'integer', 'description' => '1-100'],
                                        'degrees' => ['type' => 'integer'],
                                        'text'   => ['type' => 'string', 'description' => 'Watermark text'],
                                    ],
                                ],
                            ],
                            'output_format' => ['type' => 'string'],
                        ],
                        'required'   => ['input_path', 'operations'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'extract_text',
                    'description' => 'Extract text from images or PDFs using Tesseract OCR',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'input_path' => ['type' => 'string'],
                            'language'   => ['type' => 'string', 'description' => 'Language code, e.g. "eng", "fra"', 'default' => 'eng'],
                            'page_range' => ['type' => 'string', 'description' => 'PDF page range e.g. "1-5"'],
                        ],
                        'required'   => ['input_path'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'scan_file',
                    'description' => 'Scan a file for malware using ClamAV before processing or storing',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'file_path' => ['type' => 'string'],
                        ],
                        'required'   => ['file_path'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'store_media',
                    'description' => 'Store a processed media file to MinIO/S3',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'local_path'  => ['type' => 'string'],
                            'remote_key'  => ['type' => 'string', 'description' => 'Storage path/key'],
                            'bucket'      => ['type' => 'string', 'enum' => ['ops-storage', 'ops-media', 'ops-uploads']],
                            'public'      => ['type' => 'boolean'],
                        ],
                        'required'   => ['local_path', 'remote_key'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'get_media_info',
                    'description' => 'Get metadata about a media file (duration, dimensions, codec, size)',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'file_path' => ['type' => 'string'],
                        ],
                        'required'   => ['file_path'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'generate_thumbnail',
                    'description' => 'Generate a thumbnail image from a video at a specific timestamp',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'video_path' => ['type' => 'string'],
                            'timestamp'  => ['type' => 'string', 'description' => 'HH:MM:SS'],
                            'width'      => ['type' => 'integer'],
                            'height'     => ['type' => 'integer'],
                        ],
                        'required'   => ['video_path'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'generate_image',
                    'description' => 'Generate an image from a text prompt using Stability AI. Returns a storage URL for the generated image.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'prompt'         => ['type' => 'string', 'description' => 'Detailed image generation prompt'],
                            'negative_prompt'=> ['type' => 'string', 'description' => 'Elements to avoid in the image'],
                            'aspect_ratio'   => ['type' => 'string', 'enum' => ['1:1', '16:9', '9:16', '4:3', '3:4'], 'description' => 'Image aspect ratio'],
                            'style_preset'   => ['type' => 'string', 'enum' => ['photographic', 'digital-art', 'anime', 'cinematic', 'comic-book', 'enhance'], 'description' => 'Visual style'],
                        ],
                        'required'   => ['prompt'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'remove_background',
                    'description' => 'Remove the background from an image using Remove.bg API',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'image_url'   => ['type' => 'string', 'description' => 'URL or storage path of the source image'],
                            'output_format' => ['type' => 'string', 'enum' => ['png', 'jpg', 'zip'], 'description' => 'Output format (png preserves transparency)'],
                        ],
                        'required'   => ['image_url'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'enhance_image',
                    'description' => 'Upscale and enhance an image using Stability AI upscaler (up to 4x resolution)',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'image_url'  => ['type' => 'string', 'description' => 'URL or storage path of the source image'],
                            'prompt'     => ['type' => 'string', 'description' => 'Optional enhancement guidance prompt'],
                            'creativity' => ['type' => 'number', 'description' => 'Creative enhancement level 0.0–0.35 (higher = more creative changes)'],
                        ],
                        'required'   => ['image_url'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'generate_video',
                    'description' => 'Generate a short video from a text prompt or image using Runway Gen-3. Returns a storage URL when complete.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'prompt'      => ['type' => 'string', 'description' => 'Video generation prompt'],
                            'image_url'   => ['type' => 'string', 'description' => 'Optional source image URL for image-to-video'],
                            'duration'    => ['type' => 'integer', 'enum' => [5, 10], 'description' => 'Video duration in seconds'],
                            'ratio'       => ['type' => 'string', 'enum' => ['1280:768', '768:1280'], 'description' => 'Video aspect ratio'],
                        ],
                        'required'   => ['prompt'],
                    ],
                ],
            ],
        ];
    }

    // ─── Tool Implementations ─────────────────────────────────────

    private function toolScanFile(array $args): string
    {
        try {
            $result = $this->clamav->scan($args['file_path']);

            if ($result['infected']) {
                Log::alert("Malware detected", [
                    'file'    => $args['file_path'],
                    'threats' => $result['threats'],
                ]);
                // Delete infected file immediately
                if (file_exists($args['file_path'])) {
                    unlink($args['file_path']);
                }
            }

            return $this->toolResult(true, $result);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolGetMediaInfo(array $args): string
    {
        try {
            $isVideo = in_array(
                strtolower(pathinfo($args['file_path'], PATHINFO_EXTENSION)),
                ['mp4', 'mov', 'avi', 'mkv', 'webm']
            );

            $info = $isVideo
                ? $this->ffmpeg->getVideoInfo($args['file_path'])
                : $this->images->getImageInfo($args['file_path']);

            return $this->toolResult(true, $info);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolTranscodeVideo(array $args, AgentJob $job): string
    {
        try {
            $preset = config("agents.media.video_presets.{$args['preset']}");
            if (! $preset) {
                return $this->toolResult(false, null, "Unknown preset: {$args['preset']}");
            }

            $outputPath = $this->ffmpeg->transcode(
                input:     $args['input_path'],
                format:    $args['format'],
                width:     $preset['width'],
                height:    $preset['height'],
                bitrate:   $preset['bitrate'],
                audioBitrate: $preset['audio_bitrate'],
                startTime: $args['start_time'] ?? null,
                duration:  $args['duration']   ?? null,
            );

            return $this->toolResult(true, [
                'output_path' => $outputPath,
                'preset'      => $args['preset'],
                'format'      => $args['format'],
            ]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolProcessImage(array $args, AgentJob $job): string
    {
        try {
            $outputPath = $this->images->processWithOperations(
                input:      $args['input_path'],
                operations: $args['operations'],
                format:     $args['output_format'] ?? null,
            );

            return $this->toolResult(true, ['output_path' => $outputPath]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolExtractText(array $args): string
    {
        try {
            $text = $this->ocr->extractText(
                filePath:  $args['input_path'],
                language:  $args['language'] ?? 'eng',
                pageRange: $args['page_range'] ?? null,
            );

            return $this->toolResult(true, [
                'text'       => $text,
                'char_count' => strlen($text),
            ]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolStoreMedia(array $args, AgentJob $job): string
    {
        try {
            $disk   = Storage::disk('minio');
            $bucket = $args['bucket'] ?? 'ops-media';
            $key    = $args['remote_key'];

            $contents = file_get_contents($args['local_path']);
            if ($contents === false) {
                return $this->toolResult(false, null, "Cannot read local file: {$args['local_path']}");
            }

            $disk->put($key, $contents);

            $url = $args['public'] ?? false
                ? $disk->url($key)
                : $disk->temporaryUrl($key, now()->addHours(24));

            // Clean up temp file
            if (str_starts_with($args['local_path'], storage_path('app/temp'))) {
                @unlink($args['local_path']);
            }

            return $this->toolResult(true, ['key' => $key, 'url' => $url, 'bucket' => $bucket]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolGenerateThumbnail(array $args, AgentJob $job): string
    {
        try {
            $outputPath = $this->ffmpeg->generateThumbnail(
                videoPath: $args['video_path'],
                timestamp: $args['timestamp'] ?? '00:00:03',
                width:     $args['width']     ?? 1280,
                height:    $args['height']    ?? 720,
            );

            return $this->toolResult(true, ['thumbnail_path' => $outputPath]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolGenerateImage(array $args, AgentJob $job): string
    {
        $apiKey = config('services.stability_ai.api_key');
        if (! $apiKey) {
            return $this->toolResult(false, null, 'Stability AI API key not configured. Set STABILITY_AI_API_KEY in environment.');
        }

        try {
            $payload = [
                'prompt'          => $args['prompt'],
                'output_format'   => 'webp',
                'aspect_ratio'    => $args['aspect_ratio'] ?? '1:1',
            ];
            if (! empty($args['negative_prompt'])) {
                $payload['negative_prompt'] = $args['negative_prompt'];
            }
            if (! empty($args['style_preset'])) {
                $payload['style_preset'] = $args['style_preset'];
            }

            $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
                ->accept('image/*')
                ->asMultipart()
                ->post('https://api.stability.ai/v2beta/stable-image/generate/core', $payload);

            if (! $response->successful()) {
                $err = $response->json('errors.0') ?? $response->body();
                return $this->toolResult(false, null, "Stability AI error: {$err}");
            }

            $filename = 'generated/' . $job->id . '_' . uniqid() . '.webp';
            Storage::disk('minio')->put($filename, $response->body());
            $url = Storage::disk('minio')->temporaryUrl($filename, now()->addDays(7));

            return $this->toolResult(true, ['url' => $url, 'storage_key' => $filename, 'prompt' => $args['prompt']]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolRemoveBackground(array $args, AgentJob $job): string
    {
        $apiKey = config('services.removebg.api_key');
        if (! $apiKey) {
            return $this->toolResult(false, null, 'Remove.bg API key not configured. Set REMOVEBG_API_KEY in environment.');
        }

        try {
            $outputFormat = $args['output_format'] ?? 'png';

            $response = \Illuminate\Support\Facades\Http::withHeaders(['X-Api-Key' => $apiKey])
                ->post('https://api.remove.bg/v1.0/removebg', [
                    'image_url'     => $args['image_url'],
                    'size'          => 'auto',
                    'format'        => $outputFormat,
                ]);

            if (! $response->successful()) {
                $err = $response->json('errors.0.title') ?? $response->body();
                return $this->toolResult(false, null, "Remove.bg error: {$err}");
            }

            $filename = 'processed/nobg_' . $job->id . '_' . uniqid() . '.' . $outputFormat;
            Storage::disk('minio')->put($filename, $response->body());
            $url = Storage::disk('minio')->temporaryUrl($filename, now()->addDays(7));

            return $this->toolResult(true, ['url' => $url, 'storage_key' => $filename]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolEnhanceImage(array $args, AgentJob $job): string
    {
        $apiKey = config('services.stability_ai.api_key');
        if (! $apiKey) {
            return $this->toolResult(false, null, 'Stability AI API key not configured. Set STABILITY_AI_API_KEY in environment.');
        }

        try {
            $imageData = \Illuminate\Support\Facades\Http::get($args['image_url'])->body();

            $payload = [
                'output_format' => 'webp',
                'creativity'    => (float) ($args['creativity'] ?? 0.2),
            ];
            if (! empty($args['prompt'])) {
                $payload['prompt'] = $args['prompt'];
            }

            $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
                ->accept('image/*')
                ->asMultipart()
                ->attach('image', $imageData, 'source.webp', ['Content-Type' => 'image/webp'])
                ->post('https://api.stability.ai/v2beta/stable-image/upscale/conservative', $payload);

            if (! $response->successful()) {
                $err = $response->json('errors.0') ?? $response->body();
                return $this->toolResult(false, null, "Stability AI enhance error: {$err}");
            }

            $filename = 'enhanced/' . $job->id . '_' . uniqid() . '.webp';
            Storage::disk('minio')->put($filename, $response->body());
            $url = Storage::disk('minio')->temporaryUrl($filename, now()->addDays(7));

            return $this->toolResult(true, ['url' => $url, 'storage_key' => $filename]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolGenerateVideo(array $args, AgentJob $job): string
    {
        $apiKey = config('services.runway.api_key');
        if (! $apiKey) {
            return $this->toolResult(false, null, 'Runway API key not configured. Set RUNWAY_API_KEY in environment.');
        }

        try {
            $payload = [
                'promptText'  => $args['prompt'],
                'model'       => 'gen3a_turbo',
                'duration'    => $args['duration'] ?? 5,
                'ratio'       => $args['ratio'] ?? '1280:768',
            ];
            if (! empty($args['image_url'])) {
                $payload['promptImage'] = $args['image_url'];
            }

            // Submit generation task
            $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
                ->withHeaders(['X-Runway-Version' => '2024-11-06'])
                ->post('https://api.dev.runwayml.com/v1/image_to_video', $payload);

            if (! $response->successful()) {
                $err = $response->json('error') ?? $response->body();
                return $this->toolResult(false, null, "Runway error: {$err}");
            }

            $taskId = $response->json('id');

            // Poll for completion (max 60s for short clips)
            $maxAttempts = 12;
            for ($i = 0; $i < $maxAttempts; $i++) {
                sleep(5);
                $poll = \Illuminate\Support\Facades\Http::withToken($apiKey)
                    ->withHeaders(['X-Runway-Version' => '2024-11-06'])
                    ->get("https://api.dev.runwayml.com/v1/tasks/{$taskId}");

                $status = $poll->json('status');
                if ($status === 'SUCCEEDED') {
                    $videoUrl = $poll->json('output.0');
                    return $this->toolResult(true, ['url' => $videoUrl, 'task_id' => $taskId, 'prompt' => $args['prompt']]);
                }
                if ($status === 'FAILED') {
                    $err = $poll->json('failure') ?? 'Generation failed';
                    return $this->toolResult(false, null, "Runway generation failed: {$err}");
                }
            }

            return $this->toolResult(false, null, "Runway video generation timed out after 60s. Task ID: {$taskId}");
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }
}
