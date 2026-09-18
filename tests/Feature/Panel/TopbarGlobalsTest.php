<?php

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\Website;
use App\Services\ContentCache;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Üst şerit (faz 53b): lokasyon sayısı metni `texts.topbar_count` ({count}, boş = gizli) ve telefon/WhatsApp/e-posta
 * site ayarı görsel editörden düzenlenir — yalnız website.manage yazar, content.edit'in gönderdiği iletişim yok sayılır.
 */
class TopbarGlobalsTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(LocationSeeder::class);
        $this->seed(WebsiteSeeder::class);
        $this->seed(SiteBlockSeeder::class);
        $this->site = Website::query()->default()->firstOrFail();
    }

    #[Test]
    public function ust_serit_lokasyon_sayisi_ve_telefon_editorden_duzenlenir(): void
    {
        $admin = $this->staff('system_admin');   // content.edit + content.publish + website.manage
        $ops = $this->staff('operations_admin'); // content.edit; website.manage yok
        $this->site->forceFill(['contact_phone' => '0 850 000 00 00'])->save();

        $home = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('14 lokasyon', $home);
        $this->assertStringContainsString('0 850 000 00 00', $home);

        // Editör çerçevesi: sayı metni ve telefon işaretli.
        $frame = $this->actingAs($admin)->get(URL::temporarySignedRoute('site.preview', now()->addMinutes(5), ['website' => $this->site->id, 'editor' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString('data-ofv-global="texts.topbar_count"', $frame);
        $this->assertStringContainsString('data-ofv-count="14"', $frame);
        $this->assertStringContainsString('data-ofv-site-field="contact_phone"', $frame);
        $this->actingAs($admin)->get('/panel/icerik/tasarim')->assertOk()->assertSee('&quot;canSiteSettings&quot;:true', false)->assertSee('&quot;contact_phone&quot;:&quot;0 850 000 00 00&quot;', false);

        // Yetkisiz iletişim: taslak kaydedilir, site ayarı değişmez.
        $payload = ['sections' => [], 'globals' => ['texts' => ['topbar_count' => '{count} şube'], 'contact' => ['contact_phone' => '0 850 111 11 11']]];
        $this->actingAs($ops)->put("/panel/icerik/tasarim/{$this->site->id}/taslak", ['payload' => json_encode($payload)])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('0 850 000 00 00', $this->site->fresh()->contact_phone);

        // Yetkili: telefon + e-posta anında site ayarına (audit), sayı metni taslakta → yayınla → vitrinde.
        $payload['globals']['contact'] = ['contact_phone' => '0 850 222 22 22', 'contact_email' => 'merhaba@ornek.com', 'whatsapp_number' => ''];
        $this->actingAs($admin)->put("/panel/icerik/tasarim/{$this->site->id}/taslak", ['payload' => json_encode($payload)])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['0 850 222 22 22', 'merhaba@ornek.com'], [$this->site->fresh()->contact_phone, $this->site->fresh()->contact_email]);
        $this->assertTrue(AuditLog::query()->where('action', 'website.settings_updated')->where('entity_id', $this->site->id)->exists());
        $this->actingAs($admin)->from('/panel/icerik/tasarim')->put("/panel/icerik/tasarim/{$this->site->id}/taslak", ['payload' => json_encode(['sections' => [], 'globals' => ['contact' => ['contact_email' => 'bozuk']]])])->assertSessionHasErrors();
        $this->get('http://localhost/')->assertOk()->assertSee('0 850 222 22 22')->assertSee('14 lokasyon')->assertDontSee('14 şube');
        $this->actingAs($admin)->post("/panel/icerik/tasarim/{$this->site->id}/yayinla")->assertRedirect();
        app(ContentCache::class)->invalidate($this->site);
        $this->assertStringContainsString('14 şube', $this->get('http://localhost/')->assertOk()->getContent());

        // Boş metin sayıyı gizler; {count} içermeyen metin diğer sayfalarda da basılır.
        $this->actingAs($admin)->put("/panel/icerik/tasarim/{$this->site->id}/taslak", ['payload' => json_encode(['sections' => [], 'globals' => ['texts' => ['topbar_count' => '']]])])->assertRedirect();
        $this->actingAs($admin)->post("/panel/icerik/tasarim/{$this->site->id}/yayinla")->assertRedirect();
        app(ContentCache::class)->invalidate($this->site);
        $live = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringNotContainsString('14 şube', $live);
        preg_match('~<div class="topbar">.*?<header~s', $live, $m);
        $this->assertStringNotContainsString('lokasyon', mb_strtolower($m[0] ?? '')); // hero süzgeç satırı ve lokasyon bölümü ayrı; üst şeritte sayı yok
    }
}
