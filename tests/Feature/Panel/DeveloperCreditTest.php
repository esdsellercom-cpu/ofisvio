<?php

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Services\SettingsService;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Geliştirici / attribution metni merkezi ayardan (general.developer_credit) gelir: varsayılan "Turgut KARAKAYA",
 * panelden değiştirilince vitrin footer'ı, giriş ekranı ve panel kenar çubuğu aynı istekte güncellenir (ayar önbelleği
 * düşer), boş bırakılınca varsayılana döner. Hiçbir görünür alanda Claude/Anthropic attribution ifadesi yoktur.
 */
class DeveloperCreditTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    #[Test]
    public function attribution_ayardan_gelir_panelden_degisir_ve_bos_birakilinca_gizlenir(): void
    {
        $this->seedRbac();
        $this->seed(WebsiteSeeder::class);
        $admin = $this->staff('system_admin');

        // Varsayılan: kodda değil ayar kaydında (default) — vitrin, giriş ekranı, panel.
        $this->assertSame('Turgut KARAKAYA', app(SettingsService::class)->string('general.developer_credit'));
        $this->get('http://localhost/')->assertOk()->assertSee('Geliştirme: Turgut KARAKAYA');
        $this->get('http://localhost/login')->assertOk()->assertSee('Geliştirme: Turgut KARAKAYA');
        $this->actingAs($admin)->get('/panel/operasyon')->assertOk()->assertSee('Geliştirme: Turgut KARAKAYA');
        $this->actingAs($admin)->get('/panel/ayarlar?grup=general')->assertOk()->assertSee('Geliştirici / attribution')->assertSee('Turgut KARAKAYA');

        // Panel → DB → vitrin: değişiklik anında yansır (ayar önbelleği düşer), audit düşer.
        $this->actingAs($admin)->put('/panel/ayarlar?grup=general', ['general__developer_credit' => 'Turgut KARAKAYA · Ofis Yazılım'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(AuditLog::query()->where('entity_type', 'setting:general.developer_credit@installation')->exists());
        auth()->logout(); // giriş ekranı misafir ister
        $this->get('http://localhost/')->assertOk()->assertSee('Geliştirme: Turgut KARAKAYA · Ofis Yazılım');
        $this->get('http://localhost/login')->assertOk()->assertSee('Turgut KARAKAYA · Ofis Yazılım');

        // Boş → ayar kaydı silinir, varsayılana döner (ayar sistemi kuralı).
        $this->actingAs($admin)->put('/panel/ayarlar?grup=general', ['general__developer_credit' => ''])->assertRedirect()->assertSessionHasNoErrors();
        auth()->logout();
        $this->get('http://localhost/')->assertOk()->assertSee('Geliştirme: Turgut KARAKAYA')->assertDontSee('Ofis Yazılım');

        // Görünür alanlarda AI aracı attribution'ı yok (vitrin, giriş, panel, ayarlar).
        auth()->logout();
        $pages = [$this->get('http://localhost/'), $this->get('http://localhost/login')];
        $pages[] = $this->actingAs($admin)->get('/panel/operasyon');
        $pages[] = $this->actingAs($admin)->get('/panel/ayarlar');

        foreach ($pages as $response) {
            $html = $response->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('/(powered|developed|built|created|generated)\s+(by|with)\s+(claude|anthropic)/i', $html);
            $this->assertDoesNotMatchRegularExpression('/claude\s*(code|\.ai|ai)\b/i', $html);
        }
    }
}
