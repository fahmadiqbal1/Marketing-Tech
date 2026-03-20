<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Log;

class ClamAVService
{
    private string $host;
    private int    $port;
    private int    $timeout;

    public function __construct()
    {
        $this->host    = config('agents.media.clamav_host', 'clamav');
        $this->port    = (int) config('agents.media.clamav_port', 3310);
        $this->timeout = 30;
    }

    /**
     * Scan a file for malware via clamd TCP socket.
     * Returns ['infected' => bool, 'result' => string, 'threats' => array]
     */
    public function scan(string $filePath): array
    {
        if (! file_exists($filePath)) {
            throw new \RuntimeException("File not found for ClamAV scan: {$filePath}");
        }

        $fileSize = filesize($filePath);
        if ($fileSize === 0) {
            return ['infected' => false, 'result' => 'OK (empty file)', 'threats' => []];
        }

        try {
            $result = $this->scanViaTcp($filePath);
            Log::info("ClamAV scan complete", ['file' => basename($filePath), 'result' => $result]);
            return $result;
        } catch (\Throwable $e) {
            Log::warning("ClamAV TCP scan failed, trying CLI fallback", ['error' => $e->getMessage()]);
            return $this->scanViaCli($filePath);
        }
    }

    /**
     * Scan via clamd TCP INSTREAM command.
     */
    private function scanViaTcp(string $filePath): array
    {
        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);

        if (! $socket) {
            throw new \RuntimeException("Cannot connect to ClamAV at {$this->host}:{$this->port} — {$errstr}");
        }

        stream_set_timeout($socket, $this->timeout);

        // Send INSTREAM command
        fwrite($socket, "nINSTREAM\n");

        $handle = fopen($filePath, 'rb');
        if (! $handle) {
            fclose($socket);
            throw new \RuntimeException("Cannot open file for scanning: {$filePath}");
        }

        // Stream file in 1024-byte chunks
        while (! feof($handle)) {
            $chunk     = fread($handle, 1024);
            $chunkSize = strlen($chunk);
            fwrite($socket, pack('N', $chunkSize) . $chunk);
        }
        fclose($handle);

        // Signal end of stream
        fwrite($socket, pack('N', 0));

        $response = trim(fgets($socket));
        fclose($socket);

        return $this->parseResponse($response);
    }

    /**
     * Fallback: scan via clamscan CLI tool.
     */
    private function scanViaCli(string $filePath): array
    {
        $cmd    = 'clamscan --no-summary ' . escapeshellarg($filePath) . ' 2>&1';
        $output = shell_exec($cmd) ?? '';
        $lines  = array_filter(explode("\n", trim($output)));
        $result = end($lines) ?: 'ERROR';

        return $this->parseResponse("stream: {$result}");
    }

    private function parseResponse(string $response): array
    {
        // Expected formats:
        //   "stream: OK"
        //   "stream: Eicar-Test-Signature FOUND"
        //   "stream: ERROR"

        if (str_contains($response, 'OK')) {
            return ['infected' => false, 'result' => 'OK', 'threats' => []];
        }

        if (str_contains($response, 'FOUND')) {
            // Extract threat name: "stream: Eicar-Test-Signature FOUND"
            preg_match('/stream:\s+(.+)\s+FOUND/i', $response, $matches);
            $threat = $matches[1] ?? 'unknown';

            return [
                'infected' => true,
                'result'   => "INFECTED: {$threat}",
                'threats'  => [$threat],
            ];
        }

        // ERROR or unexpected
        Log::error("ClamAV unexpected response", ['response' => $response]);
        return ['infected' => false, 'result' => "ERROR: {$response}", 'threats' => []];
    }

    /**
     * Check if clamd is reachable.
     */
    public function ping(): bool
    {
        try {
            $socket = @fsockopen($this->host, $this->port, $errno, $errstr, 3);
            if ($socket) {
                fwrite($socket, "nPING\n");
                $response = trim(fgets($socket));
                fclose($socket);
                return $response === 'PONG';
            }
            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get ClamAV version and database info.
     */
    public function version(): string
    {
        try {
            $socket = @fsockopen($this->host, $this->port, $errno, $errstr, 3);
            if ($socket) {
                fwrite($socket, "nVERSION\n");
                $response = trim(fgets($socket));
                fclose($socket);
                return $response;
            }
            return 'unavailable';
        } catch (\Throwable) {
            return 'unavailable';
        }
    }
}
