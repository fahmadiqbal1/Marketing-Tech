<?php

namespace App\Skills;

use App\Services\Media\ImageService;
use App\Services\Media\FFmpegService;
use App\Services\Media\OCRService;
use App\Services\Security\ClamAVService;
use App\Services\AI\AIRouter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageEnhanceSkill implements SkillInterface
{
    public function __construct(private readonly ImageService $images) {}

    public function getName(): string { return 'image_enhance'; }
    public function getDescription(): string { return 'Sharpen, denoise, and auto-correct levels on an image using ImageMagick'; }

    public function getInputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'storage_key' => ['type' => 'string', 'description' => 'MinIO key of source image'],
                'sharpen'     => ['type' => 'boolean', 'description' => 'Apply unsharp mask'],
                'denoise'     => ['type' => 'boolean', 'description' => 'Reduce noise'],
                'auto_levels' => ['type' => 'boolean', 'description' => 'Auto white balance'],
                'quality'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ],
            'required' => ['storage_key'],
        ];
    }

    public function execute(array $params, ?string $workflowId = null): array
    {
        $localPath = $this->downloadFromStorage($params['storage_key']);

        try {
            $outputPath = $this->images->enhance($localPath, [
                'sharpen'     => $params['sharpen']     ?? true,
                'denoise'     => $params['denoise']     ?? false,
                'auto_levels' => $params['auto_levels'] ?? true,
                'quality'     => $params['quality']     ?? 85,
            ]);

            $outputKey = 'enhanced/' . Str::uuid() . '.' . pathinfo($outputPath, PATHINFO_EXTENSION);
            Storage::disk('minio')->put($outputKey, file_get_contents($outputPath));
            @unlink($localPath);
            @unlink($outputPath);

            return ['output_key' => $outputKey, 'success' => true];
        } catch (\Throwable $e) {
            @unlink($localPath);
            throw $e;
        }
    }

    private function downloadFromStorage(string $key): string
    {
        $content  = Storage::disk('minio')->get($key);
        $ext      = pathinfo($key, PATHINFO_EXTENSION) ?: 'jpg';
        $tempPath = storage_path('app/temp/' . Str::uuid() . '.' . $ext);
        @mkdir(dirname($tempPath), 0755, true);
        file_put_contents($tempPath, $content);
        return $tempPath;
    }
}
