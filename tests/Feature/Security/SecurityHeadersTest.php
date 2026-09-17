<?php

namespace Tests\Feature\Security;

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
    }
}
