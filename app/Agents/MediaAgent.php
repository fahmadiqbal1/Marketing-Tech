<?php

namespace App\Agents;

use App\Models\AgentJob;
use App\Services\AI\AnthropicService;
use App\Services\AI\GeminiService;
use App\Services\AI\AIRouter;
use App\Services\AI\OpenAIService;
use App\Services\AI\SwarmOrchestratorService;
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
use Illuminate\Support\Str;

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
        AIRouter $aiRouter,
        SwarmOrchestratorService $swarm,
    ) {
        parent::__construct($openai, $anthropic, $gemini, $telegram, $knowledge, $credentials, $iterationEngine, $campaignContext, $aiRouter, $swarm);
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
            'generate_thumbnail'         => $this->toolGenerateThumbnail($args, $job),
            'generate_image'             => $this->toolGenerateImage($args, $job),
            'remove_background'          => $this->toolRemoveBackground($args, $job),
            'create_platform_variants'   => $this->toolCreatePlatformVariants($args, $job),
            'generate_voiceover'         => $this->toolGenerateVoiceover($args, $job),
            'add_captions'               => $this->toolAddCaptions($args, $job),
            'add_audio_to_video'         => $this->toolAddAudioToVideo($args, $job),
            'generate_video_from_images' => $this->toolGenerateVideoFromImages($args, $job),
            default           => $this->toolResult(false, null, "Unknown tool: {$name}"),
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
                    'description' => 'Generate a marketing image using DALL-E 3',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'prompt'   => ['type' => 'string', 'description' => 'Detailed image description'],
                            'size'     => ['type' => 'string', 'enum' => ['1024x1024', '1792x1024', '1024x1792'], 'description' => 'Image dimensions'],
                            'quality'  => ['type' => 'string', 'enum' => ['standard', 'hd'], 'default' => 'standard'],
                            'style'    => ['type' => 'string', 'enum' => ['vivid', 'natural'], 'default' => 'vivid'],
                        ],
                        'required'   => ['prompt'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'remove_background',
                    'description' => 'Remove the background from an image, returning a PNG with transparency',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'storage_key' => ['type' => 'string', 'description' => 'MinIO storage key of source image'],
                        ],
                        'required'   => ['storage_key'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'create_platform_variants',
                    'description' => 'Resize/reformat a source image into variants for each target platform',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'storage_key' => ['type' => 'string', 'description' => 'MinIO key of source image'],
                            'platforms'   => [
                                'type'  => 'array',
                                'items' => ['type' => 'string', 'enum' => ['instagram', 'instagram_story', 'tiktok', 'linkedin', 'twitter', 'youtube', 'facebook']],
                                'description' => 'Platforms to create variants for',
                            ],
                        ],
                        'required'   => ['storage_key', 'platforms'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'generate_voiceover',
                    'description' => 'Generate a voiceover audio file from text using OpenAI TTS',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'text'  => ['type' => 'string', 'description' => 'Script for the voiceover'],
                            'voice' => ['type' => 'string', 'enum' => ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'], 'default' => 'nova'],
                            'model' => ['type' => 'string', 'enum' => ['tts-1', 'tts-1-hd'], 'default' => 'tts-1'],
                            'speed' => ['type' => 'number', 'description' => '0.25 to 4.0, default 1.0'],
                        ],
                        'required'   => ['text'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'add_captions',
                    'description' => 'Burn SRT subtitle captions into a video for accessibility',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'video_key'    => ['type' => 'string', 'description' => 'MinIO key of source video'],
                            'caption_text' => ['type' => 'string', 'description' => 'Text to convert to SRT captions'],
                        ],
                        'required'   => ['video_key', 'caption_text'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'add_audio_to_video',
                    'description' => 'Merge a voiceover or music audio file into a video',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'video_key'    => ['type' => 'string', 'description' => 'MinIO key of source video'],
                            'audio_key'    => ['type' => 'string', 'description' => 'MinIO key of audio file'],
                            'volume_mix'   => ['type' => 'number', 'description' => 'Audio volume 0.0-1.0, default 0.8'],
                        ],
                        'required'   => ['video_key', 'audio_key'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'generate_video_from_images',
                    'description' => 'Create a social media video slideshow from an array of images',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'image_keys'      => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Ordered MinIO keys of images'],
                            'sec_per_image'   => ['type' => 'number', 'description' => 'Seconds each image displays, default 3.0'],
                            'output_format'   => ['type' => 'string', 'enum' => ['vertical_9_16', 'square_1_1', 'landscape_16_9'], 'default' => 'vertical_9_16'],
                        ],
                        'required'   => ['image_keys'],
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
        try {
            $result = $this->openai->generateImage(
                prompt:  $args['prompt'],
                size:    $args['size']    ?? '1024x1024',
                quality: $args['quality'] ?? 'standard',
                style:   $args['style']   ?? 'vivid',
            );

            // Download and store in MinIO so it persists
            $imageContent = file_get_contents($result['url']);
            $key          = 'generated/' . Str::uuid() . '.png';
            Storage::disk('minio')->put($key, $imageContent);

            return $this->toolResult(true, [
                'storage_key'    => $key,
                'url'            => Storage::disk('minio')->temporaryUrl($key, now()->addHours(24)),
                'revised_prompt' => $result['revised_prompt'],
            ]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolRemoveBackground(array $args, AgentJob $job): string
    {
        try {
            $disk     = Storage::disk('minio');
            $tempIn   = storage_path('app/temp/' . Str::uuid() . '.jpg');
            $content  = $disk->get($args['storage_key']);
            file_put_contents($tempIn, $content);

            $outputPath = $this->images->removeBackground($tempIn);

            $outKey = 'enhanced/' . Str::uuid() . '.png';
            $disk->put($outKey, file_get_contents($outputPath));

            @unlink($tempIn);
            @unlink($outputPath);

            return $this->toolResult(true, [
                'storage_key' => $outKey,
                'url'         => $disk->temporaryUrl($outKey, now()->addHours(24)),
            ]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolCreatePlatformVariants(array $args, AgentJob $job): string
    {
        // Platform spec: [width, height]
        $specs = [
            'instagram'       => [1080, 1080],
            'instagram_story' => [1080, 1920],
            'tiktok'          => [1080, 1920],
            'linkedin'        => [1200,  627],
            'twitter'         => [1600,  900],
            'youtube'         => [1280,  720],
            'facebook'        => [1200,  630],
        ];

        try {
            $disk    = Storage::disk('minio');
            $content = $disk->get($args['storage_key']);
            $tempIn  = storage_path('app/temp/' . Str::uuid() . '.jpg');
            file_put_contents($tempIn, $content);

            $variants = [];
            foreach ($args['platforms'] as $platform) {
                if (!isset($specs[$platform])) continue;
                [$w, $h] = $specs[$platform];

                $resized = $this->images->resize($tempIn, $w, $h);
                $outKey  = 'processed/' . $platform . '_' . Str::uuid() . '.jpg';
                $disk->put($outKey, file_get_contents($resized));
                @unlink($resized);

                $variants[$platform] = [
                    'storage_key' => $outKey,
                    'url'         => $disk->temporaryUrl($outKey, now()->addHours(24)),
                    'dimensions'  => "{$w}x{$h}",
                ];
            }

            @unlink($tempIn);
            return $this->toolResult(true, ['variants' => $variants]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolGenerateVoiceover(array $args, AgentJob $job): string
    {
        try {
            $mp3Content = $this->openai->generateVoiceover(
                text:  $args['text'],
                voice: $args['voice'] ?? 'nova',
                model: $args['model'] ?? 'tts-1',
                speed: (float) ($args['speed'] ?? 1.0),
            );

            $key  = 'uploads/' . Str::uuid() . '.mp3';
            Storage::disk('minio')->put($key, $mp3Content);

            return $this->toolResult(true, [
                'storage_key' => $key,
                'url'         => Storage::disk('minio')->temporaryUrl($key, now()->addHours(24)),
                'char_count'  => strlen($args['text']),
            ]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolAddCaptions(array $args, AgentJob $job): string
    {
        try {
            $disk    = Storage::disk('minio');
            $tempVid = storage_path('app/temp/' . Str::uuid() . '.mp4');
            file_put_contents($tempVid, $disk->get($args['video_key']));

            // Auto-generate SRT from caption text (split into ~5-word segments)
            $srtPath = $this->generateSimpleSrt($args['caption_text'], $tempVid);
            $output  = $this->ffmpeg->burnCaptions($tempVid, $srtPath);

            $outKey = 'processed/captioned_' . Str::uuid() . '.mp4';
            $disk->put($outKey, file_get_contents($output));

            @unlink($tempVid); @unlink($srtPath); @unlink($output);

            return $this->toolResult(true, [
                'storage_key' => $outKey,
                'url'         => $disk->temporaryUrl($outKey, now()->addHours(24)),
            ]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolAddAudioToVideo(array $args, AgentJob $job): string
    {
        try {
            $disk    = Storage::disk('minio');
            $tempVid = storage_path('app/temp/' . Str::uuid() . '.mp4');
            $tempAud = storage_path('app/temp/' . Str::uuid() . '.mp3');
            file_put_contents($tempVid, $disk->get($args['video_key']));
            file_put_contents($tempAud, $disk->get($args['audio_key']));

            $output = $this->ffmpeg->mergeAudio($tempVid, $tempAud, (float) ($args['volume_mix'] ?? 0.8));

            $outKey = 'processed/merged_' . Str::uuid() . '.mp4';
            $disk->put($outKey, file_get_contents($output));

            @unlink($tempVid); @unlink($tempAud); @unlink($output);

            return $this->toolResult(true, [
                'storage_key' => $outKey,
                'url'         => $disk->temporaryUrl($outKey, now()->addHours(24)),
            ]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    private function toolGenerateVideoFromImages(array $args, AgentJob $job): string
    {
        $sizeMap = [
            'vertical_9_16'  => '1080x1920',
            'square_1_1'     => '1080x1080',
            'landscape_16_9' => '1920x1080',
        ];

        try {
            $disk       = Storage::disk('minio');
            $localPaths = [];

            foreach ($args['image_keys'] as $key) {
                $tmpPath = storage_path('app/temp/' . Str::uuid() . '.jpg');
                file_put_contents($tmpPath, $disk->get($key));
                $localPaths[] = $tmpPath;
            }

            $size   = $sizeMap[$args['output_format'] ?? 'vertical_9_16'] ?? '1080x1920';
            $output = $this->ffmpeg->createSlideshow($localPaths, (float) ($args['sec_per_image'] ?? 3.0), $size);

            $outKey = 'processed/slideshow_' . Str::uuid() . '.mp4';
            $disk->put($outKey, file_get_contents($output));

            foreach ($localPaths as $p) { @unlink($p); }
            @unlink($output);

            return $this->toolResult(true, [
                'storage_key' => $outKey,
                'url'         => $disk->temporaryUrl($outKey, now()->addHours(24)),
                'image_count' => count($localPaths),
            ]);
        } catch (\Throwable $e) {
            return $this->toolResult(false, null, $e->getMessage());
        }
    }

    /**
     * Generate a simple SRT file from caption text.
     * Splits text into ~8-word segments with 2-second display per segment.
     */
    private function generateSimpleSrt(string $text, string $videoPath): string
    {
        $words    = explode(' ', trim($text));
        $chunks   = array_chunk($words, 8);
        $srtPath  = storage_path('app/temp/' . Str::uuid() . '.srt');
        $srt      = '';
        $index    = 1;
        $start    = 0.0;

        foreach ($chunks as $chunk) {
            $end = $start + 2.0;
            $srt .= "{$index}\n";
            $srt .= $this->formatSrtTime($start) . ' --> ' . $this->formatSrtTime($end) . "\n";
            $srt .= implode(' ', $chunk) . "\n\n";
            $start = $end;
            $index++;
        }

        file_put_contents($srtPath, $srt);
        return $srtPath;
    }

    private function formatSrtTime(float $seconds): string
    {
        $h  = (int) ($seconds / 3600);
        $m  = (int) (($seconds % 3600) / 60);
        $s  = (int) ($seconds % 60);
        $ms = (int) round(($seconds - floor($seconds)) * 1000);
        return sprintf('%02d:%02d:%02d,%03d', $h, $m, $s, $ms);
    }
}
