<?php

namespace App\Services\Media;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ImageService
{
    private string $convert;
    private string $identify;
    private string $tempDir;

    public function __construct()
    {
        $this->convert  = config('agents.media.imagemagick', '/usr/bin/convert');
        $this->identify = str_replace('convert', 'identify', $this->convert);
        $this->tempDir  = storage_path('app/temp');
        @mkdir($this->tempDir, 0755, true);
    }

    public function getImageInfo(string $path): array
    {
        $cmd    = escapeshellarg($this->identify) . ' -format "%wx%h %[colorspace] %[type] %Q %z" ' . escapeshellarg($path) . ' 2>/dev/null';
        $output = trim(shell_exec($cmd) ?? '');

        if (! $output) {
            return ['width' => 0, 'height' => 0, 'format' => 'unknown'];
        }

        [$dims, $colorspace, $type, $quality, $depth] = array_pad(explode(' ', $output), 5, '');
        [$width, $height] = array_map('intval', explode('x', $dims));

        return [
            'width'      => $width,
            'height'     => $height,
            'colorspace' => $colorspace,
            'type'       => $type,
            'quality'    => (int) $quality,
            'bit_depth'  => (int) $depth,
            'format'     => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
        ];
    }

    public function resize(string $input, int $width, int $height, int $quality = 85): string
    {
        $output = $this->tempPath('jpg');
        $cmd = sprintf(
            '%s %s -resize %dx%d^ -gravity center -extent %dx%d -quality %d %s 2>&1',
            escapeshellarg($this->convert),
            escapeshellarg($input),
            $width, $height, $width, $height,
            $quality,
            escapeshellarg($output)
        );
        $this->exec($cmd, "resize");
        return $output;
    }

    public function stripExif(string $input): string
    {
        $ext    = pathinfo($input, PATHINFO_EXTENSION);
        $output = $this->tempPath($ext);
        $cmd    = sprintf(
            '%s %s -strip %s 2>&1',
            escapeshellarg($this->convert),
            escapeshellarg($input),
            escapeshellarg($output)
        );
        $this->exec($cmd, "strip_exif");
        return $output;
    }

    public function normalize(string $input, array $options = []): string
    {
        $maxW    = $options['max_width']  ?? 3840;
        $maxH    = $options['max_height'] ?? 2160;
        $quality = $options['quality']    ?? 85;
        $output  = $this->tempPath('jpg');

        $cmd = sprintf(
            '%s %s -strip -auto-orient -resize %dx%d\> -quality %d %s 2>&1',
            escapeshellarg($this->convert),
            escapeshellarg($input),
            $maxW, $maxH,
            $quality,
            escapeshellarg($output)
        );
        $this->exec($cmd, "normalize");
        return $output;
    }

    public function enhance(string $input, array $options = []): string
    {
        $ext    = pathinfo($input, PATHINFO_EXTENSION) ?: 'jpg';
        $output = $this->tempPath($ext);
        $ops    = [];

        if ($options['auto_levels'] ?? true)  $ops[] = '-auto-level';
        if ($options['denoise']     ?? false)  $ops[] = '-despeckle';
        if ($options['sharpen']     ?? true)   $ops[] = '-unsharp 0x0.75+0.75+0.008';

        $quality = $options['quality'] ?? 85;
        $opStr   = implode(' ', $ops);

        $cmd = sprintf(
            '%s %s %s -quality %d %s 2>&1',
            escapeshellarg($this->convert),
            escapeshellarg($input),
            $opStr,
            $quality,
            escapeshellarg($output)
        );
        $this->exec($cmd, "enhance");
        return $output;
    }

    public function removeBackground(string $input, array $options = []): string
    {
        $output  = $this->tempPath('png');
        $fuzz    = ($options['fuzz'] ?? 10) . '%';
        $bgColor = $options['bg_color'] ?? 'white';

        // Convert to PNG and flood-fill corners with transparency
        $cmd = sprintf(
            '%s %s -fuzz %s -transparent %s -alpha set PNG:%s 2>&1',
            escapeshellarg($this->convert),
            escapeshellarg($input),
            $fuzz,
            escapeshellarg($bgColor),
            escapeshellarg($output)
        );
        $this->exec($cmd, "remove_background");
        return $output;
    }

    public function processWithOperations(string $input, array $operations, ?string $format = null): string
    {
        $ops = [];

        foreach ($operations as $op) {
            $type = $op['type'] ?? '';

            switch ($type) {
                case 'resize':
                    $w = $op['width']  ?? 1920;
                    $h = $op['height'] ?? 1080;
                    $ops[] = "-resize {$w}x{$h}>";
                    break;
                case 'crop':
                    $w = $op['width']  ?? 800;
                    $h = $op['height'] ?? 600;
                    $ops[] = "-gravity center -extent {$w}x{$h}";
                    break;
                case 'rotate':
                    $d = $op['degrees'] ?? 90;
                    $ops[] = "-rotate {$d}";
                    break;
                case 'optimize':
                    $q = $op['quality'] ?? 82;
                    $ops[] = "-quality {$q} -strip";
                    break;
                case 'watermark':
                    if (! empty($op['text'])) {
                        $text  = escapeshellarg($op['text']);
                        $ops[] = "-gravity SouthEast -pointsize 20 -fill 'rgba(255,255,255,0.6)' -annotate +10+10 {$text}";
                    }
                    break;
            }
        }

        $ext    = $format ?? (pathinfo($input, PATHINFO_EXTENSION) ?: 'jpg');
        $output = $this->tempPath($ext);
        $opStr  = implode(' ', $ops);

        $cmd = sprintf(
            '%s %s %s %s 2>&1',
            escapeshellarg($this->convert),
            escapeshellarg($input),
            $opStr,
            escapeshellarg($output)
        );
        $this->exec($cmd, "process_operations");
        return $output;
    }

    private function exec(string $cmd, string $context): void
    {
        Log::debug("ImageMagick [{$context}]");
        exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException("ImageMagick [{$context}] failed: " . implode(' ', $output));
        }
    }

    private function tempPath(string $ext): string
    {
        return $this->tempDir . '/' . Str::uuid() . '.' . $ext;
    }
}
