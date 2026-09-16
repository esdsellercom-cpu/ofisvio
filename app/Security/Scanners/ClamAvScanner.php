<?php

namespace App\Security\Scanners;

use App\Security\MalwareScanner;
use App\Security\ScanResult;
use Throwable;

/**
 * ClamAV daemon (clamd) — INSTREAM protokolü.
 *
 * Dosya, clamd'ye soket üzerinden AKTARILIR (INSTREAM): clamd'nin dosya
 * sistemine erişmesi gerekmez, bu yüzden clamd ayrı bir konteynerde/sunucuda
 * çalışabilir. Protokol:
 *   -> "zINSTREAM\0"
 *   -> [4 bayt big-endian uzunluk][veri] ... [0x00000000]
 *   <- "stream: OK\0"  |  "stream: <imza> FOUND\0"  |  "... ERROR\0"
 *
 * Bağlantı/protokol hatası "temiz" DEĞİL "yapılamadı"dır; çağıran fail-closed
 * davranır (KycService yüklemeyi reddeder).
 */
final class ClamAvScanner implements MalwareScanner
{
    private const CHUNK_SIZE = 8192;

    public function __construct(
        private readonly string $address,   // tcp://host:3310 | unix:///var/run/clamav/clamd.ctl
        private readonly int $timeoutSeconds = 30,
    ) {}

    public function scan(string $path): ScanResult
    {
        $file = @fopen($path, 'rb');

        if ($file === false) {
            return ScanResult::unavailable('Dosya okunamadı: '.$path);
        }

        try {
            $socket = @stream_socket_client($this->address, $errno, $errstr, $this->timeoutSeconds);

            if ($socket === false) {
                return ScanResult::unavailable("clamd'ye bağlanılamadı ({$this->address}): {$errstr}");
            }

            stream_set_timeout($socket, $this->timeoutSeconds);

            try {
                $this->write($socket, "zINSTREAM\0");

                while (! feof($file)) {
                    $chunk = fread($file, self::CHUNK_SIZE);

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    $this->write($socket, pack('N', strlen($chunk)).$chunk);
                }

                $this->write($socket, pack('N', 0));

                $response = stream_get_contents($socket);
            } finally {
                fclose($socket);
            }
        } catch (Throwable $e) {
            return ScanResult::unavailable('clamd iletişim hatası: '.$e->getMessage());
        } finally {
            fclose($file);
        }

        return $this->interpret((string) $response);
    }

    /** @param  resource  $socket */
    private function write($socket, string $bytes): void
    {
        $total = strlen($bytes);
        $written = 0;

        while ($written < $total) {
            $n = fwrite($socket, substr($bytes, $written));

            if ($n === false || $n === 0) {
                throw new \RuntimeException('sokete yazılamadı');
            }

            $written += $n;
        }
    }

    private function interpret(string $response): ScanResult
    {
        $response = trim($response, "\0\n\r ");

        if ($response === '') {
            return ScanResult::unavailable("clamd'den yanıt alınamadı");
        }

        if (str_ends_with($response, 'OK')) {
            return ScanResult::clean();
        }

        if (preg_match('/^stream:\s*(.+?)\s+FOUND$/', $response, $m) === 1) {
            return ScanResult::infected($m[1]);
        }

        // "INSTREAM size limit exceeded. ERROR" vb.: temiz DEĞİL, yapılamadı.
        return ScanResult::unavailable('clamd: '.$response);
    }
}
