<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Audit kuralları (kaynak taraması, çalışma zamanı kodu):
 *   - Uygulama kodunda mock/dummy/fake/demo veri yapısı yok (test dizini hariç).
 *   - config/ dizininde ticari veri (fiyat, telefon, e-posta) yok — kaynak veritabanı.
 *   - Tarayıcı depolama API'leri (localStorage vb.) hiçbir görünüm/JS'te yok.
 *   - Görünümlerde çağrılan route adları gerçekten tanımlı (ölü UI yok) — bkz. ApiRouteConsistencyTest.
 */
class MockDataDetectionTest extends TestCase
{
    /** @return array<int, string> */
    private static function files(string $dir, string $pattern): array
    {
        $root = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$dir;
        $out = [];

        if (! is_dir($root)) {
            return $out;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && preg_match($pattern, $file->getFilename()) === 1) {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }

    #[Test]
    public function uygulama_kodunda_mock_demo_veri_yapisi_yok(): void
    {
        $offenders = [];
        // Tanımlayıcı olarak mock/dummy/fake/demo: $mockUsers, mockData, fakeCustomers, demoInvoices …
        $pattern = '/\$(mock|dummy|fake|demo|sample|placeholder)[A-Za-z]*\s*=|\b(mock|dummy|fake|demo)(Data|Users|Customers|Companies|Bookings|Invoices|Products|Items)\b/i';

        foreach (array_merge(self::files('app', '/\.php$/'), self::files('resources', '/\.(php|js)$/'), self::files('routes', '/\.php$/'), self::files('public/js', '/\.js$/')) as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match($pattern, $source, $m) === 1) {
                $offenders[] = basename($path).': '.$m[0];
            }
        }

        $this->assertSame([], $offenders, "Çalışma zamanı kodunda mock/demo veri yapısı:\n".implode("\n", $offenders));
    }

    #[Test]
    public function config_dizininde_ticari_veri_yok(): void
    {
        $offenders = [];

        foreach (self::files('config', '/\.php$/') as $path) {
            $source = (string) file_get_contents($path);

            foreach (['₺', 'TL/', '/ay\'', '/gün\'', '@ofisvio.com', '0850 '] as $needle) {
                if (str_contains($source, $needle)) {
                    $offenders[] = basename($path).' içeriyor: '.$needle;
                }
            }
        }

        $this->assertSame([], $offenders, "config/ ticari veri taşıyor (kaynak veritabanı olmalı):\n".implode("\n", $offenders));
    }

    #[Test]
    public function tarayici_depolamasi_kullanilmiyor(): void
    {
        $offenders = [];

        foreach (array_merge(self::files('resources', '/\.(php|js)$/'), self::files('public/js', '/\.js$/')) as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match('/\b(localStorage|sessionStorage|indexedDB|document\.cookie)\b/', $source, $m) === 1) {
                $offenders[] = basename($path).': '.$m[0];
            }
        }

        $this->assertSame([], $offenders, "Tarayıcı depolaması iş verisi için yasak:\n".implode("\n", $offenders));
    }

    #[Test]
    public function backend_dis_saglayiciya_yalniz_gateway_uzerinden_cikar(): void
    {
        // Http facade / curl / dış file_get_contents yalnız Integration Gateway'de (faz 5).
        $offenders = [];

        foreach (self::files('app', '/\.php$/') as $path) {
            if (str_ends_with($path, 'Gateway.php')) {
                continue;
            }

            $source = (string) file_get_contents($path);

            if (preg_match('/\bHttp::|curl_init\(|file_get_contents\(\s*[\'"]https?:/', $source, $m) === 1) {
                $offenders[] = basename($path).': '.$m[0];
            }
        }

        $this->assertSame([], $offenders, "Gateway dışında dış HTTP çağrısı:\n".implode("\n", $offenders));
    }

    #[Test]
    public function frontend_dis_saglayiciya_dogrudan_baglanmiyor(): void
    {
        // JS'te fetch/XHR/WebSocket ile dış adrese çağrı yok; form POST'ları ve bağlantılar sunucuya gider.
        $offenders = [];

        foreach (self::files('public/js', '/\.js$/') as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match('/\b(fetch|axios|XMLHttpRequest|WebSocket)\s*\(/', $source, $m) === 1) {
                $offenders[] = basename($path).': '.$m[0];
            }
        }

        $this->assertSame([], $offenders, "Frontend doğrudan HTTP istemcisi kullanıyor:\n".implode("\n", $offenders));
    }

    #[Test]
    public function kaynak_kodda_sabit_telefon_whatsapp_ve_fiyat_yok(): void
    {
        // Master prompt §40/§48: telefon (+90…, 0xxx xxx xx xx), wa.me sabit adresi ve ₺ fiyat
        // literalleri uygulama kodunda/görünümlerde/JS'te/config'te/seeder'da bulunamaz — kaynak
        // veritabanı/ayar. placeholder="…" biçim örnekleri (kullanıcıya biçim gösterir) muaftır.
        $patterns = [
            'telefon' => '/(?<![\w.])(\+90\s?\d{3}\s?\d{3}\s?\d{2}\s?\d{2}|0\d{3}\s\d{3}\s\d{2}\s\d{2})(?![\w])/u',
            'wa.me sabit adres' => '~wa\.me/\d+~',
            'fiyat literali' => '/(?<![{$\w])\d{1,3}(\.\d{3})*\s?₺(?![}])/u',
        ];
        $files = array_merge(self::files('app', '/\.php$/'), self::files('resources/views', '/\.blade\.php$/'), self::files('public/js', '/\.js$/'), self::files('config', '/\.php$/'), self::files('database/seeders', '/\.php$/'));
        $offenders = [];

        foreach ($files as $path) {
            $source = (string) file_get_contents($path);

            foreach ($patterns as $label => $pattern) {
                preg_match_all($pattern, $source, $m);

                foreach ($m[0] as $hit) {
                    if (preg_match('/placeholder="[^"]*'.preg_quote($hit, '/').'/u', $source) === 1) {
                        continue;
                    }

                    $offenders[] = str_replace(dirname(__DIR__, 2).DIRECTORY_SEPARATOR, '', $path).": {$label} → {$hit}";
                }
            }
        }

        $offenders = array_values(array_unique($offenders));
        $this->assertSame([], $offenders, "Kaynak kodda sabit ticari veri:\n".implode("\n", $offenders));
    }
}
