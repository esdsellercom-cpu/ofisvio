<?php

namespace Tests\Unit;

use App\Security\Scanners\ClamAvScanner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ClamAvScanner — INSTREAM protokolü, sahte bir clamd sürecine karşı.
 * Gerçek soket, gerçek çerçeveleme; yalnızca imza motoru sahte.
 */
class ClamAvScannerTest extends TestCase
{
    /** @var resource|null */
    private $daemon = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private function startFakeClamd(string $mode = 'normal'): string
    {
        $script = dirname(__DIR__).'/Support/fake-clamd.php';
        $cmd = [PHP_BINARY, $script, '0', $mode];

        $this->daemon = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);
        $this->assertIsResource($this->daemon, 'sahte clamd başlatılamadı');

        $port = trim((string) fgets($this->pipes[1]));
        $this->assertNotSame('', $port, 'sahte clamd port bildirmedi');

        return "tcp://127.0.0.1:{$port}";
    }

    protected function tearDown(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($this->daemon)) {
            proc_terminate($this->daemon);
            proc_close($this->daemon);
        }

        parent::tearDown();
    }

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $content);

        return $path;
    }

    #[Test]
    public function temiz_dosya_ok_doner(): void
    {
        $address = $this->startFakeClamd();
        // Parça sınırını (8192) aşan içerik: çerçeveleme birden fazla chunk'ta da doğru olmalı.
        $path = $this->tempFile(str_repeat("%PDF-1.4 temiz icerik\n", 1000));

        $result = (new ClamAvScanner($address, 5))->scan($path);

        $this->assertTrue($result->available);
        $this->assertTrue($result->clean);
        $this->assertFalse($result->isInfected());
    }

    #[Test]
    public function zararli_isaret_yakalanir(): void
    {
        $address = $this->startFakeClamd();
        // Gerçek EICAR değil (bkz. fake-clamd.php): yerel antivirüs dosyayı silerdi.
        $path = $this->tempFile('onemsiz icerik OFISVIO-FAKE-MALWARE-MARKER onemsiz');

        $result = (new ClamAvScanner($address, 5))->scan($path);

        $this->assertTrue($result->isInfected());
        $this->assertSame('Win.Test.EICAR_HDB-1', $result->signature);
    }

    #[Test]
    public function clamd_hatasi_temiz_sayilmaz(): void
    {
        $address = $this->startFakeClamd('error');
        $path = $this->tempFile('x');

        $result = (new ClamAvScanner($address, 5))->scan($path);

        $this->assertFalse($result->available);
        $this->assertFalse($result->clean);
        $this->assertStringContainsString('ERROR', (string) $result->signature);
    }

    #[Test]
    public function yanit_gelmezse_yapilamadi_doner(): void
    {
        $address = $this->startFakeClamd('silent');
        $path = $this->tempFile('x');

        $result = (new ClamAvScanner($address, 5))->scan($path);

        $this->assertFalse($result->available);
    }

    #[Test]
    public function baglanti_yoksa_yapilamadi_doner(): void
    {
        // Dinleyen kimse yok: bağlantı reddedilir; sonuç "temiz" DEĞİL.
        $result = (new ClamAvScanner('tcp://127.0.0.1:1', 1))->scan($this->tempFile('x'));

        $this->assertFalse($result->available);
        $this->assertFalse($result->clean);
    }
}
