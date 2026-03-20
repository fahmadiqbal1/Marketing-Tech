<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Jobs\ProcessMediaAssetJob;
use App\Services\Security\ClamAVService;
use App\Services\AI\AIRouter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MediaPipelineService
{
    private array $allowedMimes = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic',
        'video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/webm', 'video/x-matroska',
        'application/pdf',
        'text/plain', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public function __construct(
        private readonly ClamAVService $clamav,
        private readonly FFmpegService $ffmpeg,
        private readonly ImageService  $images,
        private readonly OCRService    $ocr,
        private readonly AIRouter      $aiRouter,
    ) {}

    /**
     * Stage 1 — Ingest: download from Telegram, store in quarantine, queue for processing.
     */
    public function ingest(
        string $fileContent,
        string $originalName,
        string $mimeType,
        int    $userId,
        int    $chatId,
        ?string $workflowId = null,
    ): MediaAsset {
        $ext      = $this->extensionFromMime($mimeType);
        $tempKey  = 'quarantine/' . Str::uuid() . '.' . $ext;

        // Store in quarantine bucket
        Storage::disk('minio')->put($tempKey, $fileContent);

        $asset = MediaAsset::create([
            'workflow_id'           => $workflowId,
            'original_name'         => $originalName,
            'storage_key'           => $tempKey,
            'storage_bucket'        => 'ops-uploads',
            'mime_type'             => $mimeType,
            'extension'             => $ext,
            'file_size_bytes'       => strlen($fileContent),
            'status'                => 'queued',
            'uploaded_by_user_id'   => $userId,
            'uploaded_via_chat_id'  => $chatId,
        ]);

        Log::info("Media asset ingested", ['id' => $asset->id, 'mime' => $mimeType]);

        // Queue full pipeline
        ProcessMediaAssetJob::dispatch($asset->id)->onQueue('media');

        return $asset;
    }

    /**
     * Run the complete 8-step pipeline on a queued asset.
     * Called by ProcessMediaAssetJob.
     */
    public function processAsset(string $assetId, array $options = []): array
    {
        $asset = MediaAsset::findOrFail($assetId);
        $asset->update(['status' => 'scanning']);

        try {
            // ── Step 1: Download from quarantine ──────────────────
            $localPath = $this->downloadAsset($asset);
            $asset->addProcessingLog('download', true, 'Downloaded from quarantine');

            // ── Step 2: MIME validation ────────────────────────────
            $this->validateMime($localPath, $asset->mime_type);
            $asset->addProcessingLog('mime_validation', true, "MIME verified: {$asset->mime_type}");

            // ── Step 3: ClamAV virus scan ──────────────────────────
            $scanResult = $this->clamav->scan($localPath);
            $asset->update([
                'virus_clean'  => ! $scanResult['infected'],
                'clamav_result' => $scanResult['result'],
                'scanned_at'   => now(),
            ]);
            $asset->addProcessingLog('clamav', ! $scanResult['infected'], $scanResult['result']);

            if ($scanResult['infected']) {
                @unlink($localPath);
                Storage::disk('minio')->delete($asset->storage_key);
                $asset->update(['status' => 'rejected']);
                Log::alert("Malware detected and rejected", ['asset_id' => $assetId, 'threats' => $scanResult['threats']]);
                return ['success' => false, 'rejected' => true, 'reason' => 'malware'];
            }

            $asset->update(['status' => 'processing']);

            // ── Step 4: Metadata stripping (EXIF removal) ─────────
            $localPath = $this->stripMetadata($localPath, $asset);

            // ── Step 5 / 6: Format-specific processing ────────────
            $processedPath = $localPath;
            $metadata      = [];

            if ($asset->isImage()) {
                [$processedPath, $metadata] = $this->processImage($localPath, $asset, $options);
            } elseif ($asset->isVideo()) {
                [$processedPath, $metadata] = $this->processVideo($localPath, $asset, $options);
            } elseif ($asset->isPdf()) {
                $metadata = $this->processPdf($localPath, $asset);
            }

            // ── Step 7: OCR extraction ─────────────────────────────
            $extractedText = $this->extractText($processedPath, $asset);
            if ($extractedText) {
                $asset->update(['extracted_text' => $extractedText]);
                $asset->addProcessingLog('ocr', true, 'Text extracted: ' . strlen($extractedText) . ' chars');
            }

            // ── Step 8: Content classification ────────────────────
            $category = $this->classifyContent($processedPath, $asset, $extractedText);

            // Store processed file
            $processedKey = 'processed/' . Str::uuid() . '.' . $asset->extension;
            Storage::disk('minio')->put($processedKey, file_get_contents($processedPath));

            // Generate thumbnail for video/image
            $thumbnailKey = null;
            if ($asset->isVideo() || $asset->isImage()) {
                $thumbnailKey = $this->generateThumbnail($processedPath, $asset);
            }

            // Clean up local temp files
            @unlink($localPath);
            if ($processedPath !== $localPath) {
                @unlink($processedPath);
            }

            $asset->update([
                'status'           => 'ready',
                'processed_key'    => $processedKey,
                'thumbnail_key'    => $thumbnailKey,
                'content_category' => $category,
                'metadata'         => array_merge($asset->metadata ?? [], $metadata),
                'processed_at'     => now(),
            ]);

            $asset->addProcessingLog('complete', true, 'Pipeline completed successfully');
            Log::info("Media pipeline completed", ['asset_id' => $assetId]);

            return [
                'success'         => true,
                'asset_id'        => $asset->id,
                'processed_key'   => $processedKey,
                'thumbnail_key'   => $thumbnailKey,
                'category'        => $category,
                'extracted_text'  => $extractedText ? substr($extractedText, 0, 500) : null,
            ];

        } catch (\Throwable $e) {
            Log::error("Media pipeline failed", ['asset_id' => $assetId, 'error' => $e->getMessage()]);
            $asset->update(['status' => 'failed']);
            $asset->addProcessingLog('error', false, $e->getMessage());
            throw $e;
        }
    }

    // ── Private pipeline steps ────────────────────────────────────

    private function downloadAsset(MediaAsset $asset): string
    {
        $content = Storage::disk('minio')->get($asset->storage_key);
        if ($content === null) {
            throw new \RuntimeException("Cannot download asset from storage: {$asset->storage_key}");
        }
        $tempPath = storage_path('app/temp/' . Str::uuid() . '.' . $asset->extension);
        @mkdir(dirname($tempPath), 0755, true);
        file_put_contents($tempPath, $content);
        return $tempPath;
    }

    private function validateMime(string $localPath, string $declaredMime): void
    {
        $detectedMime = mime_content_type($localPath);

        if (! in_array($detectedMime, $this->allowedMimes)) {
            throw new \RuntimeException("Disallowed file type: {$detectedMime}");
        }

        // Prevent MIME spoofing (e.g. PHP file renamed to .jpg)
        $imageMimes = ['image/jpeg','image/png','image/webp','image/gif'];
        $videoMimes = ['video/mp4','video/quicktime','video/x-msvideo','video/webm'];

        if (in_array($declaredMime, $imageMimes) && ! in_array($detectedMime, $imageMimes)) {
            throw new \RuntimeException("MIME mismatch: declared {$declaredMime}, detected {$detectedMime}");
        }

        if (in_array($declaredMime, $videoMimes) && ! in_array($detectedMime, $videoMimes)) {
            throw new \RuntimeException("MIME mismatch: declared {$declaredMime}, detected {$detectedMime}");
        }
    }

    private function stripMetadata(string $localPath, MediaAsset $asset): string
    {
        // Strip EXIF from images (removes GPS coordinates and personal data)
        if ($asset->isImage()) {
            return $this->images->stripExif($localPath);
        }
        return $localPath;
    }

    private function processImage(string $localPath, MediaAsset $asset, array $options): array
    {
        $info = $this->images->getImageInfo($localPath);

        // Normalize: cap at 4K, convert HEIC to JPEG
        $processed = $this->images->normalize($localPath, [
            'max_width'  => 3840,
            'max_height' => 2160,
            'quality'    => 85,
        ]);

        $metadata = [
            'width'    => $info['width']  ?? null,
            'height'   => $info['height'] ?? null,
            'format'   => $info['format'] ?? null,
            'channels' => $info['channels'] ?? null,
        ];

        $asset->addProcessingLog('image_normalize', true, "Normalized to {$metadata['width']}x{$metadata['height']}");
        return [$processed, $metadata];
    }

    private function processVideo(string $localPath, MediaAsset $asset, array $options): array
    {
        $info   = $this->ffmpeg->getVideoInfo($localPath);
        $preset = $options['preset'] ?? 'web';
        $config = config("agents.media.video_presets.{$preset}", config('agents.media.video_presets.web'));

        $processed = $this->ffmpeg->transcode($localPath, 'mp4',
            $config['width'], $config['height'], $config['bitrate'], $config['audio_bitrate']
        );

        $metadata = [
            'duration_s' => $info['duration'] ?? null,
            'codec'      => $info['codec']     ?? null,
            'fps'        => $info['fps']       ?? null,
            'width'      => $info['width']     ?? null,
            'height'     => $info['height']    ?? null,
            'bitrate'    => $info['bitrate']   ?? null,
            'preset'     => $preset,
        ];

        $asset->addProcessingLog('video_transcode', true, "Transcoded to {$preset} MP4");
        return [$processed, $metadata];
    }

    private function processPdf(string $localPath, MediaAsset $asset): array
    {
        $metadata = [
            'pages'     => $this->getPdfPageCount($localPath),
            'file_size' => filesize($localPath),
        ];
        $asset->addProcessingLog('pdf_info', true, "PDF: {$metadata['pages']} pages");
        return $metadata;
    }

    private function extractText(string $localPath, MediaAsset $asset): ?string
    {
        if ($asset->isImage() || $asset->isPdf()) {
            try {
                return $this->ocr->extractText($localPath);
            } catch (\Throwable $e) {
                Log::warning("OCR failed", ['error' => $e->getMessage()]);
                return null;
            }
        }
        return null;
    }

    private function classifyContent(string $localPath, MediaAsset $asset, ?string $extractedText): string
    {
        // Use AI to classify content type
        if ($extractedText && strlen($extractedText) > 50) {
            $prompt = "Classify this document in one word (resume|invoice|contract|article|form|image|video|other):\n\n" . substr($extractedText, 0, 500);
            try {
                $category = trim(strtolower($this->aiRouter->complete($prompt, 'gpt-4o-mini', 20, 0.0)));
                $valid    = ['resume','invoice','contract','article','form','image','video','other'];
                return in_array($category, $valid) ? $category : 'other';
            } catch (\Throwable) {
                return 'other';
            }
        }

        if ($asset->isImage()) return 'image';
        if ($asset->isVideo()) return 'video';
        if ($asset->isPdf())   return 'pdf';
        return 'other';
    }

    private function generateThumbnail(string $localPath, MediaAsset $asset): ?string
    {
        try {
            if ($asset->isVideo()) {
                $thumbPath = $this->ffmpeg->generateThumbnail($localPath, '00:00:02', 640, 360);
            } else {
                $thumbPath = $this->images->resize($localPath, 640, 360);
            }

            $key = 'thumbnails/' . Str::uuid() . '.jpg';
            Storage::disk('minio')->put($key, file_get_contents($thumbPath));
            @unlink($thumbPath);
            return $key;
        } catch (\Throwable $e) {
            Log::warning("Thumbnail generation failed", ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getPdfPageCount(string $path): int
    {
        $output = shell_exec("pdfinfo " . escapeshellarg($path) . " 2>/dev/null | grep Pages");
        if ($output) {
            preg_match('/\d+/', $output, $m);
            return (int) ($m[0] ?? 1);
        }
        return 1;
    }

    private function extensionFromMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg'                    => 'jpg',
            'image/png'                     => 'png',
            'image/webp'                    => 'webp',
            'image/gif'                     => 'gif',
            'image/heic'                    => 'heic',
            'video/mp4'                     => 'mp4',
            'video/quicktime'               => 'mov',
            'video/x-msvideo'               => 'avi',
            'video/webm'                    => 'webm',
            'application/pdf'               => 'pdf',
            'text/plain'                    => 'txt',
            default                         => 'bin',
        };
    }
}
