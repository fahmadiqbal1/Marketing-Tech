<?php

namespace App\Services\Media;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class OCRService
{
    private string $tesseract;
    private string $tempDir;

    public function __construct()
    {
        $this->tesseract = config('agents.media.tesseract', '/usr/bin/tesseract');
        $this->tempDir   = storage_path('app/temp');
        @mkdir($this->tempDir, 0755, true);
    }

    /**
     * Extract text from an image or PDF file.
     */
    public function extractText(string $filePath, string $language = 'eng', ?string $pageRange = null): string
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // For PDFs, convert pages to images first then OCR
        if ($ext === 'pdf') {
            return $this->extractFromPdf($filePath, $language, $pageRange);
        }

        return $this->ocrImage($filePath, $language);
    }

    private function ocrImage(string $imagePath, string $language): string
    {
        $outputBase = $this->tempDir . '/' . Str::uuid();

        $cmd = sprintf(
            '%s %s %s -l %s --oem 3 --psm 3 txt 2>/dev/null',
            escapeshellarg($this->tesseract),
            escapeshellarg($imagePath),
            escapeshellarg($outputBase),
            escapeshellarg($language)
        );

        exec($cmd, $output, $exitCode);
        $textFile = $outputBase . '.txt';

        if ($exitCode !== 0 || ! file_exists($textFile)) {
            Log::warning("Tesseract OCR failed", ['file' => $imagePath, 'exit' => $exitCode]);
            return '';
        }

        $text = file_get_contents($textFile);
        @unlink($textFile);

        return trim($text ?? '');
    }

    private function extractFromPdf(string $pdfPath, string $language, ?string $pageRange): string
    {
        // Convert PDF pages to images using ImageMagick then OCR each
        $tempImageBase = $this->tempDir . '/pdf_' . Str::uuid();

        $cmd = sprintf(
            '/usr/bin/convert -density 300 %s %s-%%04d.png 2>/dev/null',
            escapeshellarg($pdfPath),
            escapeshellarg($tempImageBase)
        );

        exec($cmd, $out, $exitCode);

        if ($exitCode !== 0) {
            // Fallback: try pdftotext
            $textOut = $this->tempDir . '/' . Str::uuid() . '.txt';
            exec("pdftotext " . escapeshellarg($pdfPath) . " " . escapeshellarg($textOut) . " 2>/dev/null");
            if (file_exists($textOut)) {
                $text = file_get_contents($textOut);
                @unlink($textOut);
                return trim($text ?? '');
            }
            return '';
        }

        $pages = glob($tempImageBase . '-*.png') ?: [];
        sort($pages);

        $allText = [];
        foreach ($pages as $page) {
            $text = $this->ocrImage($page, $language);
            if ($text) {
                $allText[] = $text;
            }
            @unlink($page);
        }

        return implode("\n\n", $allText);
    }
}
