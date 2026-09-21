<?php

namespace Tests\Feature\Security;

use App\Support\Csp;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Audit S-1: her web yanıtında güvenlik başlıkları; panel çerçevelenemez, vitrin yalnız kendi origin'inde; HSTS yalnız HTTPS. */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function vitrin_ve_panel_yanitlari_guvenlik_basliklari_tasir(): void
    {
        $this->seed(WebsiteSeeder::class);

        $site = $this->get('http://localhost/')->assertOk();
        $site->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $csp = (string) $site->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString('https://fonts.googleapis.com', $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString('camera=()', (string) $site->headers->get('Permissions-Policy'));
        $this->assertNull($site->headers->get('Strict-Transport-Security'), 'HTTP yanıtında HSTS olmamalı.');

        $login = $this->get('http://localhost/login')->assertOk();
        $login->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $login->headers->get('Content-Security-Policy'));

        $secure = $this->get('https://localhost/login')->assertOk();
        $this->assertSame('max-age=31536000; includeSubDomains', $secure->headers->get('Strict-Transport-Security'));

        // Audit F-12: proxy güveni tanımsızken X-Forwarded-Proto yok sayılır (HSTS yok); TRUSTED_PROXIES ile HTTPS algılanır.
        $forwarded = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])->withHeaders(['X-Forwarded-Proto' => 'https'])->get('http://localhost/login')->assertOk();
        $this->assertNull($forwarded->headers->get('Strict-Transport-Security'));
        config(['ofisvio.security.trusted_proxies' => '10.0.0.0/8']);
        $trusted = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])->withHeaders(['X-Forwarded-Proto' => 'https'])->get('http://localhost/login')->assertOk();
        $this->assertSame('max-age=31536000; includeSubDomains', $trusted->headers->get('Strict-Transport-Security'));
    }

    #[Test]
    public function csp_script_src_nonce_tasir_unsafe_inline_yok_ve_satir_ici_scriptler_nonce_alir(): void
    {
        // Audit F-11: script-src 'unsafe-inline' YOK; istek başına nonce başlıkta ve her satır içi <script>'te aynı;
        // satır içi olay öznitelikleri (onclick/onsubmit/onchange) görünümlerde yok — data-confirm/data-autosubmit ile.
        $this->seed(WebsiteSeeder::class);

        $site = $this->get('http://localhost/')->assertOk();
        $csp = (string) $site->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9]{24})' 'strict-dynamic'/", $csp);
        preg_match("/'nonce-([A-Za-z0-9]{24})'/", $csp, $m);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
        $html = $site->getContent();
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)(?![^>]*\bnonce=)(?![^>]*type="application\/ld\+json")[^>]*>/', $html, 'nonce\'suz satır içi script');
        $this->assertDoesNotMatchRegularExpression('/\son(click|submit|change|load|input)=/i', $html, 'satır içi olay özniteliği');

        // İkinci istek farklı nonce üretir (tahmin edilemez).
        $again = (string) $this->get('http://localhost/')->headers->get('Content-Security-Policy');
        preg_match("/'nonce-([A-Za-z0-9]{24})'/", $again, $m2);
        $this->assertNotSame($m[1], $m2[1]);

        // Yönetici HTML'i: nonce'suz script otomatik nonce alır; olan korunur.
        $nonce = Csp::nonce();
        $this->assertSame('<script nonce="'.$nonce.'">a()</script><script nonce="x">b()</script>', Csp::withNonce('<script>a()</script><script nonce="x">b()</script>'));

        // Görünüm kaynak taraması: hiçbir blade dosyasında satır içi olay özniteliği kalmadı.
        $violations = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php') && preg_match('/\son(click|submit|change|load|input|keyup|keydown)="/i', (string) file_get_contents($file->getPathname()))) {
                $violations[] = $file->getFilename();
            }
        }
        $this->assertSame([], $violations);
    }
}
