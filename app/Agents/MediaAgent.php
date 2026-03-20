<?php

namespace App\Agents;

use App\Models\AgentJob;
use App\Services\Media\FFmpegService;
use App\Services\Media\ImageService;
use App\Services\Media\OCRService;
use App\Services\Security\ClamAVService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class MediaAgent extends BaseAgent
{
    protected string $agentType = 'media';

    public function __construct(
        \App\Services\AI\OpenAIService        $openai,
        \App\Services\AI\AnthropicService     $anthropic,
        \App\Services\Telegram\TelegramBotService $telegram,
        \App\Services\Knowledge\VectorStoreService $knowledge,
        private readonly FFmpegService        $ffmpeg,
        private readonly ImageService         $images,
        private readonly OCRService           $ocr,
        private readonly ClamAVService        $clamav,
    ) {
        parent::__construct($openai, $anthropic, $telegram, $knowledge);
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
            'generate_thumbnail' => $this->toolGenerateThumbnail($args, $job),
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
}
