<?php

namespace App\Skills;

use App\Services\Media\ImageService;
use App\Services\AI\AIRouter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BackgroundRemoveSkill implements SkillInterface
{
    public function __construct(private readonly ImageService $images) {}

    public function getName(): string { return 'background_remove'; }
    public function getDescription(): string { return 'Remove image background using ImageMagick flood-fill'; }

    public function getInputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'storage_key' => ['type' => 'string'],
                'fuzz'        => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100, 'description' => 'Color similarity tolerance %'],
                'background_color' => ['type' => 'string', 'description' => 'Hex color to remove e.g. #FFFFFF'],
            ],
            'required' => ['storage_key'],
        ];
    }

    public function execute(array $params, ?string $workflowId = null): array
    {
        $content  = Storage::disk('minio')->get($params['storage_key']);
        $tempPath = storage_path('app/temp/' . Str::uuid() . '.png');
        @mkdir(dirname($tempPath), 0755, true);
        file_put_contents($tempPath, $content);

        try {
            $outputPath = $this->images->removeBackground($tempPath, [
                'fuzz'       => $params['fuzz']             ?? 10,
                'bg_color'   => $params['background_color'] ?? '#FFFFFF',
            ]);

            $key = 'processed/bg_removed_' . Str::uuid() . '.png';
            Storage::disk('minio')->put($key, file_get_contents($outputPath));
            @unlink($tempPath);
            @unlink($outputPath);

            return ['output_key' => $key, 'format' => 'png', 'transparent' => true];
        } catch (\Throwable $e) {
            @unlink($tempPath);
            throw $e;
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 4. ocr_extract
// ─────────────────────────────────────────────────────────────────────────────
