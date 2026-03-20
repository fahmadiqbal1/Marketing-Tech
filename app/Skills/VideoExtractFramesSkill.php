<?php

namespace App\Skills;

use App\Services\Media\ImageService;
use App\Services\Media\FFmpegService;
use App\Services\Media\OCRService;
use App\Services\Security\ClamAVService;
use App\Services\AI\AIRouter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoExtractFramesSkill implements SkillInterface
{
    public function __construct(private readonly FFmpegService $ffmpeg) {}

    public function getName(): string { return 'video_extract_frames'; }
    public function getDescription(): string { return 'Extract frames from a video at specified intervals using FFmpeg'; }

    public function getInputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'storage_key' => ['type' => 'string'],
                'fps'         => ['type' => 'number', 'description' => 'Frames per second to extract (e.g. 1 = 1 frame/sec)'],
                'start_time'  => ['type' => 'string', 'description' => 'HH:MM:SS'],
                'duration'    => ['type' => 'string', 'description' => 'HH:MM:SS'],
                'max_frames'  => ['type' => 'integer'],
            ],
            'required' => ['storage_key'],
        ];
    }

    public function execute(array $params, ?string $workflowId = null): array
    {
        $content  = Storage::disk('minio')->get($params['storage_key']);
        $ext      = pathinfo($params['storage_key'], PATHINFO_EXTENSION) ?: 'mp4';
        $tempPath = storage_path('app/temp/' . Str::uuid() . '.' . $ext);
        @mkdir(dirname($tempPath), 0755, true);
        file_put_contents($tempPath, $content);

        try {
            $outputDir  = storage_path('app/temp/frames_' . Str::uuid());
            @mkdir($outputDir, 0755, true);

            $frames = $this->ffmpeg->extractFrames($tempPath, $outputDir, [
                'fps'       => $params['fps']       ?? 1,
                'start'     => $params['start_time'] ?? null,
                'duration'  => $params['duration']   ?? null,
                'max'       => $params['max_frames']  ?? 30,
            ]);

            $storedKeys = [];
            foreach ($frames as $framePath) {
                $key = 'frames/' . Str::uuid() . '.jpg';
                Storage::disk('minio')->put($key, file_get_contents($framePath));
                $storedKeys[] = $key;
                @unlink($framePath);
            }

            @unlink($tempPath);
            @rmdir($outputDir);

            return ['frame_keys' => $storedKeys, 'count' => count($storedKeys)];
        } catch (\Throwable $e) {
            @unlink($tempPath);
            throw $e;
        }
    }
}
