<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FFmpegService
{
    private string $ffmpeg;
    private string $ffprobe;
    private string $tempDir;

    public function __construct()
    {
        $this->ffmpeg  = config('agents.media.ffmpeg',  '/usr/bin/ffmpeg');
        $this->ffprobe = config('agents.media.ffprobe', '/usr/bin/ffprobe');
        $this->tempDir = storage_path('app/temp');
        @mkdir($this->tempDir, 0755, true);
    }

    /**
     * Transcode video to target format and resolution.
     */
    public function transcode(
        string  $input,
        string  $format,
        int     $width,
        int     $height,
        string  $bitrate      = '2000k',
        string  $audioBitrate = '128k',
        ?string $startTime    = null,
        ?string $duration     = null,
    ): string {
        $output = $this->tempPath($format);

        $cmd = [
            $this->ffmpeg,
            '-y',
            '-i', $input,
        ];

        if ($startTime) {
            $cmd = array_merge($cmd, ['-ss', $startTime]);
        }

        if ($duration) {
            $cmd = array_merge($cmd, ['-t', $duration]);
        }

        // Video encoding
        $cmd = array_merge($cmd, [
            '-vf',        "scale={$width}:{$height}:force_original_aspect_ratio=decrease,pad={$width}:{$height}:(ow-iw)/2:(oh-ih)/2",
            '-c:v',       'libx264',
            '-b:v',       $bitrate,
            '-maxrate',   $bitrate,
            '-bufsize',   '2x' . $bitrate,
            '-preset',    'fast',
            '-profile:v', 'main',
            '-pix_fmt',   'yuv420p',
            // Audio encoding
            '-c:a',       'aac',
            '-b:a',       $audioBitrate,
            '-ar',        '44100',
            '-movflags',  '+faststart',
            $output,
        ]);

        $this->run($cmd, "transcode");
        return $output;
    }

    /**
     * Extract a thumbnail frame from a video.
     */
    public function generateThumbnail(
        string $videoPath,
        string $timestamp = '00:00:02',
        int    $width     = 1280,
        int    $height    = 720,
    ): string {
        $output = $this->tempPath('jpg');

        $cmd = [
            $this->ffmpeg, '-y',
            '-ss', $timestamp,
            '-i', $videoPath,
            '-vframes', '1',
            '-vf', "scale={$width}:{$height}:force_original_aspect_ratio=decrease",
            '-q:v', '2',
            $output,
        ];

        $this->run($cmd, "generate_thumbnail");
        return $output;
    }

    /**
     * Extract frames at a given rate.
     */
    public function extractFrames(
        string  $videoPath,
        string  $outputDir,
        array   $options = [],
    ): array {
        $fps      = $options['fps']      ?? 1;
        $start    = $options['start']    ?? null;
        $duration = $options['duration'] ?? null;
        $max      = $options['max']      ?? 30;

        @mkdir($outputDir, 0755, true);
        $pattern = $outputDir . '/frame_%04d.jpg';

        $cmd = [$this->ffmpeg, '-y'];
        if ($start)    $cmd = array_merge($cmd, ['-ss', $start]);
        if ($duration) $cmd = array_merge($cmd, ['-t', $duration]);

        $cmd = array_merge($cmd, [
            '-i', $videoPath,
            '-vf', "fps={$fps}",
            '-vframes', (string) $max,
            '-q:v', '3',
            $pattern,
        ]);

        $this->run($cmd, "extract_frames");

        return glob($outputDir . '/frame_*.jpg') ?: [];
    }

    /**
     * Extract audio from a video.
     */
    public function extractAudio(string $videoPath, string $format = 'mp3'): string
    {
        $output = $this->tempPath($format);

        $cmd = [
            $this->ffmpeg, '-y',
            '-i', $videoPath,
            '-vn',
            '-acodec', $format === 'mp3' ? 'libmp3lame' : 'aac',
            '-ab', '192k',
            '-ar', '44100',
            $output,
        ];

        $this->run($cmd, "extract_audio");
        return $output;
    }

    /**
     * Get video metadata via ffprobe.
     */
    public function getVideoInfo(string $videoPath): array
    {
        $cmd = [
            $this->ffprobe,
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_streams',
            '-show_format',
            $videoPath,
        ];

        $output = $this->runCapture($cmd, "get_video_info");
        $data   = json_decode($output, true) ?? [];

        $videoStream = collect($data['streams'] ?? [])->firstWhere('codec_type', 'video') ?? [];
        $audioStream = collect($data['streams'] ?? [])->firstWhere('codec_type', 'audio') ?? [];
        $format      = $data['format'] ?? [];

        return [
            'duration'    => (float) ($format['duration'] ?? 0),
            'size_bytes'  => (int)   ($format['size']     ?? 0),
            'bitrate'     => (int)   ($format['bit_rate'] ?? 0),
            'width'       => (int)   ($videoStream['width']  ?? 0),
            'height'      => (int)   ($videoStream['height'] ?? 0),
            'codec'       => $videoStream['codec_name']       ?? null,
            'fps'         => $this->parseFps($videoStream['r_frame_rate'] ?? '0/1'),
            'audio_codec' => $audioStream['codec_name']       ?? null,
            'channels'    => (int) ($audioStream['channels']  ?? 0),
        ];
    }

    // ── Private helpers ───────────────────────────────────────────

    private function run(array $cmd, string $context): void
    {
        $escaped  = implode(' ', array_map('escapeshellarg', $cmd));
        $escaped .= ' 2>&1';

        Log::debug("FFmpeg [{$context}]", ['cmd' => $escaped]);

        exec($escaped, $output, $exitCode);

        if ($exitCode !== 0) {
            $error = implode("\n", array_slice($output, -5)); // last 5 lines of FFmpeg output
            throw new \RuntimeException("FFmpeg [{$context}] failed (exit {$exitCode}): {$error}");
        }
    }

    private function runCapture(array $cmd, string $context): string
    {
        $escaped = implode(' ', array_map('escapeshellarg', $cmd));
        $output  = shell_exec($escaped . ' 2>/dev/null');

        if ($output === null) {
            throw new \RuntimeException("FFprobe [{$context}] returned no output");
        }

        return $output;
    }

    private function tempPath(string $extension): string
    {
        return $this->tempDir . '/' . Str::uuid() . '.' . $extension;
    }

    private function parseFps(string $fraction): float
    {
        [$num, $den] = explode('/', $fraction . '/1');
        return $den > 0 ? round((float) $num / (float) $den, 2) : 0;
    }
}
