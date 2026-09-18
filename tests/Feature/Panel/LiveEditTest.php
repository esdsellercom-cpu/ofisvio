<?php

namespace Tests\Feature\Panel;

use App\Enums\ContentStatus;
use App\Models\AuditLog;
use App\Models\Content;
use App\Models\Location;
use App\Models\Media;
use App\Models\Service;
use App\Models\SiteSection;
use App\Models\User;
use App\Models\Website;
use App\Services\ContentCache;
use App\Services\SiteBuilderService;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Canlı düzenleme (faz 59): ziyaretçiye hiçbir iz yok; yetkili oturumda düğme; mod açıkken görseller hedef işaretli
 * (component → kayıt → alan → medya); seçim/yükleme/kaldırma tek form gönderimiyle gerçek veriye yazılır ve yenilemede
 * kalıcıdır — hero, blog kapağı, hizmet kapağı, lokasyon kapağı (galeri kuralı), bölüm görseli, farklı sayfalar; yetkisiz
 * kullanıcı API üzerinden değiştiremez; dönüş adresi yalnız site içi.
 */
class LiveEditTest extends TestCase
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
        Storage::fake('public');
        Storage::fake('private');
        $this->site = Website::query()->default()->firstOrFail();
    }

    private function media(string $alt): Media
    {
        return Media::create(['website_id' => $this->site->id, 'disk' => 'public', 'path' => 'media/'.Str::slug($alt).'.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1000, 'width' => 1200, 'height' => 750, 'alt' => $alt, 'original_name' => 'x.jpg', 'checksum_sha256' => hash('sha256', $alt)]);
    }

    private function livePost(string $slug, string $title): Content
    {
        $c = Content::create(['website_id' => $this->site->id, 'kind' => 'post', 'slug' => $slug, 'title' => $title, 'body' => 'Gövde.', 'category' => 'Genel']);
        $c->forceFill(['status' => ContentStatus::PUBLISHED, 'published_at' => now()->subDay(), 'published_by' => User::query()->value('id')])->save();
        app(ContentCache::class)->invalidate($this->site);

        return $c;
    }

    #[Test]
    public function ziyaretci_hicbir_iz_gormez_yetkili_dugme_gorur_mod_acilinca_hedefler_isaretlenir(): void
    {
        $post = $this->livePost('ornek-yazi', 'Örnek yazı');
        $admin = $this->staff('system_admin');
        $finance = $this->staff('finance_admin');

        $visitor = $this->get('http://localhost/')->assertOk()->getContent();
        foreach (['data-le', 'live-edit.js', 'live-edit.css', 'Düzenleme Modu', 'data-le-modal'] as $needle) {
            $this->assertStringNotContainsString($needle, $visitor, "Ziyaretçiye sızdı: {$needle}");
        }

        // Yetkisiz personel (finans): düğme yok.
        $this->assertStringNotContainsString('Düzenleme Modu', $this->actingAs($finance)->get('http://localhost/')->assertOk()->getContent());
        $this->actingAs($finance)->post('/panel/canli/mod', ['on' => 1, 'return' => '/'])->assertForbidden();

        // Yetkili: düğme var, mod kapalı → işaret yok; aç → işaretler + modal + kütüphane.
        $home = $this->actingAs($admin)->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('✎ Düzenleme Modu', $home);
        $this->assertStringNotContainsString('data-le="', $home);
        $this->actingAs($admin)->post('/panel/canli/mod', ['on' => 1, 'return' => '/'])->assertRedirect('/');
        $home = $this->actingAs($admin)->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('Düzenleme Modu: AÇIK', $home);
        $this->assertStringContainsString('data-le="website:'.$this->site->id.':hero"', $home);
        $this->assertStringContainsString('data-le-label="Ana sayfa → Hero görseli', $home);
        $this->assertStringContainsString('data-le="content:'.$post->id.':cover"', $home);
        $this->assertStringContainsString('data-le="service:', $home);
        $this->assertStringContainsString('data-le="location:', $home);
        $this->assertStringContainsString('data-le-modal', $home);
        $this->assertStringContainsString('live-edit.js', $home);

        // Editör çerçevesi canlı düzenleme işareti taşımaz (iki sistem karışmaz); kapat → işaretler gider.
        $frame = $this->actingAs($admin)->get(URL::temporarySignedRoute('site.preview', now()->addMinutes(5), ['website' => $this->site->id, 'editor' => 1]))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-le="', $frame);
        $this->actingAs($admin)->post('/panel/canli/mod', ['on' => 0, 'return' => '/blog'])->assertRedirect('/blog');
        $this->assertStringNotContainsString('data-le="', $this->actingAs($admin)->get('http://localhost/')->assertOk()->getContent());

        // Dönüş adresi yalnız site içi.
        $this->actingAs($admin)->post('/panel/canli/mod', ['on' => 1, 'return' => 'https://kotu.example/x'])->assertRedirect('/');
        $this->actingAs($admin)->post('/panel/canli/mod', ['on' => 1, 'return' => '//kotu.example'])->assertRedirect('/');
    }

    #[Test]
    public function hero_blog_hizmet_lokasyon_ve_bolum_gorselleri_yerinde_degisir_ve_kalici_olur(): void
    {
        $admin = $this->staff('system_admin');
        $ops = $this->staff('operations_admin'); // content.edit + service.manage + geo.edit; website.manage yok
        $post = $this->livePost('ornek-yazi', 'Örnek yazı');
        $service = Service::query()->where('slug', 'sanal-ofis')->firstOrFail();
        $location = Location::query()->where('slug', 'levent-199')->firstOrFail();
        $m1 = $this->media('Yeni hero');
        $m2 = $this->media('Yazı kapağı');
        // Ziyaretçi (oturum yok) API'den değiştiremez — actingAs kalıcı olduğu için en başta.
        $this->post("/panel/canli/gorsel/icerik/{$post->id}", ['action' => 'remove'])->assertRedirect('/login');
        $this->actingAs($admin)->post('/panel/canli/mod', ['on' => 1, 'return' => '/']);

        // 1) Hero: kütüphaneden seç → kaydet → yenile → yeni görsel; alt metni medyaya işlenir; audit.
        $this->actingAs($admin)->post('/panel/canli/gorsel/site', ['action' => 'replace', 'id' => $this->site->id, 'field' => 'hero', 'media_id' => $m1->id, 'alt' => 'İş merkezi ofis', 'return' => '/'])->assertRedirect('/')->assertSessionHas('live_status');
        $this->assertSame($m1->id, $this->site->fresh()->hero_media_id);
        $this->assertSame('İş merkezi ofis', $m1->fresh()->alt);
        $home = $this->actingAs($admin)->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString($m1->url(), $home);
        $this->assertStringContainsString('data-le-media="'.$m1->id.'"', $home);
        $this->assertTrue(AuditLog::query()->where('action', 'live.image_replaced')->where('entity_type', 'website')->exists());
        // website.manage olmayan personel hero'yu değiştiremez; başka sitenin/olmayan medya reddedilir.
        $this->actingAs($ops)->post('/panel/canli/gorsel/site', ['action' => 'replace', 'media_id' => $m2->id])->assertForbidden();
        $this->actingAs($admin)->post('/panel/canli/gorsel/site', ['action' => 'replace', 'media_id' => 999999, 'return' => '/'])->assertRedirect('/')->assertSessionHas('live_error');
        $this->assertSame($m1->id, $this->site->fresh()->hero_media_id);

        // 2) Blog kapağı (ana sayfa kartı + yazı sayfası + liste aynı kayıt).
        $this->actingAs($ops)->post("/panel/canli/gorsel/icerik/{$post->id}", ['action' => 'replace', 'media_id' => $m2->id, 'return' => '/blog/ornek-yazi'])->assertRedirect('/blog/ornek-yazi');
        $this->assertSame([$m2->id, $m2->url()], [$post->fresh()->cover_media_id, $post->fresh()->cover_url]);
        $this->assertStringContainsString($m2->url(), $this->get('http://localhost/blog/ornek-yazi')->assertOk()->getContent());
        $this->assertStringContainsString($m2->url(), $this->get('http://localhost/')->assertOk()->getContent());
        $this->assertStringContainsString($m2->url(), $this->get('http://localhost/blog')->assertOk()->getContent());
        $this->assertStringContainsString('data-le="content:'.$post->id.':cover"', $this->actingAs($ops)->get('http://localhost/blog')->assertOk()->getContent());

        // 3) Hizmet kapağı: dosya yükleme (karantina zinciri) → hizmet sayfası + çözümler.
        $this->actingAs($ops)->post("/panel/canli/gorsel/hizmet/{$service->slug}", ['action' => 'replace', 'file' => UploadedFile::fake()->image('kapak.jpg', 1200, 800), 'alt' => 'Sanal ofis resepsiyonu', 'return' => '/cozum/sanal-ofis'])->assertRedirect('/cozum/sanal-ofis')->assertSessionHas('live_status');
        $service->refresh();
        $this->assertNotNull($service->cover_media_id);
        $this->assertSame('Sanal ofis resepsiyonu', $service->cover->alt);
        $this->assertStringContainsString('Sanal ofis resepsiyonu', $this->get('http://localhost/cozum/sanal-ofis')->assertOk()->getContent());
        $this->assertStringContainsString('data-le="service:'.$service->id.':cover"', $this->actingAs($ops)->get('http://localhost/cozum/sanal-ofis')->assertOk()->getContent());

        // 4) Lokasyon kapağı: galeri kuralı — görsel galeriye eklenir, kapak olur; lokasyon sayfası + liste.
        $m3 = $this->media('Şube dış cephe');
        $this->actingAs($ops)->post("/panel/canli/gorsel/lokasyon/{$location->slug}", ['action' => 'replace', 'media_id' => $m3->id, 'return' => '/lokasyon/levent-199'])->assertRedirect('/lokasyon/levent-199');
        $this->assertSame($m3->id, $location->fresh()->cover_media_id);
        $this->assertDatabaseHas('location_media', ['location_id' => $location->id, 'media_id' => $m3->id, 'category' => 'cover']);
        $this->assertStringContainsString($m3->url(), $this->get('http://localhost/lokasyon/levent-199')->assertOk()->getContent());
        $this->assertStringContainsString($m3->url(), $this->get('http://localhost/lokasyonlar')->assertOk()->getContent());

        // 5) Bölüm görseli (görsel bölümü): yayında + taslakta birlikte değişir; diğer taslak değişiklikleri yayınlanmaz.
        $this->actingAs($admin)->get('/panel/icerik/tasarim')->assertOk();
        $this->actingAs($admin)->post("/panel/icerik/tasarim/{$this->site->id}/bolum", ['type' => 'image'])->assertRedirect();
        $image = SiteSection::query()->where('type', 'image')->firstOrFail();
        $this->actingAs($admin)->post("/panel/icerik/tasarim/{$this->site->id}/yayinla")->assertRedirect();
        $rich = SiteSection::query()->where('type', 'hero')->firstOrFail();
        $rich->forceFill(['settings' => ['eyebrow' => 'YAYINLANMAMIŞ TASLAK']])->save(); // yayınlanmamış taslak değişikliği
        $m4 = $this->media('Bölüm görseli');
        $this->actingAs($admin)->post('/panel/canli/gorsel/bolum', ['action' => 'replace', 'id' => $image->id, 'field' => 'media', 'media_id' => $m4->id, 'return' => '/'])->assertRedirect('/')->assertSessionHas('live_status');
        $this->assertSame($m4->id, (int) $image->fresh()->settings['media']);
        app(ContentCache::class)->invalidate($this->site);
        $home = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString($m4->url(), $home);
        $this->assertStringNotContainsString('YAYINLANMAMIŞ TASLAK', $home);
        $this->assertTrue(app(SiteBuilderService::class)->hasUnpublishedChanges($this->site));
        $this->actingAs($admin)->post('/panel/canli/gorsel/bolum', ['action' => 'replace', 'id' => $image->id, 'field' => 'caption', 'media_id' => $m4->id, 'return' => '/'])->assertSessionHas('live_error'); // görsel alanı değil

        // 6) Kaldır: hero boşalır (illüstrasyon döner), kütüphane silinmez.
        $this->actingAs($admin)->post('/panel/canli/gorsel/site', ['action' => 'remove', 'return' => '/'])->assertRedirect('/');
        $this->assertNull($this->site->fresh()->hero_media_id);
        $this->assertNotNull($m1->fresh());
        $this->assertStringContainsString('illustrations/hero-office.svg', $this->get('http://localhost/')->assertOk()->getContent());

        // 7) Yetkisiz: ziyaretçi ve yetkisiz rol API'den değiştiremez.
        $this->actingAs($this->staff('finance_admin'))->post("/panel/canli/gorsel/icerik/{$post->id}", ['action' => 'remove'])->assertForbidden();
        $this->assertSame($m2->id, $post->fresh()->cover_media_id);
    }
}
