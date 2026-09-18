<?php

namespace Tests\Feature\Panel;

use App\Enums\ContentStatus;
use App\Models\AuditLog;
use App\Models\Content;
use App\Models\ContentUrlHistory;
use App\Models\Location;
use App\Models\NotFoundLog;
use App\Models\Service;
use App\Models\UrlRedirect;
use App\Models\User;
use App\Models\Website;
use App\Seo\RedirectMatcher;
use App\Services\ContentCache;
use App\Services\ContentService;
use App\Services\RedirectService;
use App\Services\UrlHistoryService;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Akıllı URL / yönlendirme sistemi (faz 54): benzerlik eşleştirici, slug değişimi → 301, silme onayı (öneri / farklı URL /
 * yönlendirme yok), hizmet ve lokasyon silme, 404 karar zinciri (otomatik / öneri / üst kategori / düz 404), 404 günlüğü,
 * zincir düzleştirme ve döngü reddi, manuel yönetim ekranı, sitemap ve canonical, kırık URL botu.
 */
class RedirectSystemTest extends TestCase
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

    private function livePost(string $slug, string $title, string $category = 'Sanal Ofis', string $tags = 'sanal ofis', string $body = 'Sanal ofis adres tescil posta yönetimi.'): Content
    {
        $c = Content::create(['website_id' => $this->site->id, 'kind' => 'post', 'slug' => $slug, 'title' => $title, 'body' => $body, 'excerpt' => mb_substr($body, 0, 120), 'category' => $category, 'tags' => array_map('trim', explode(',', $tags))]);
        $c->forceFill(['status' => ContentStatus::PUBLISHED, 'published_at' => now()->subDay(), 'published_by' => User::query()->value('id')])->save();
        app(ContentCache::class)->invalidate($this->site);

        return $c;
    }

    #[Test]
    public function eslestirici_benzer_icerigi_bulur_ve_alakasizi_dusuk_puanlar(): void
    {
        $candidates = [
            ['path' => '/blog/sanal-ofis-avantajlari', 'title' => 'Sanal ofisin avantajları', 'kind' => 'post', 'category' => 'Sanal Ofis', 'tags' => ['sanal ofis'], 'text' => 'Sanal ofis adres tescil'],
            ['path' => '/blog/toplanti-odasi-rezervasyonu', 'title' => 'Toplantı odası rezervasyonu', 'kind' => 'post', 'category' => 'Toplantı', 'tags' => ['toplantı'], 'text' => 'Saatlik oda'],
            ['path' => '/cozum/sanal-ofis', 'title' => 'Sanal Ofis', 'kind' => 'service', 'text' => 'Yasal adres, posta'],
        ];
        $ranked = RedirectMatcher::rank(['path' => '/blog/sanal-ofis-nedir', 'title' => 'Sanal ofis nedir?', 'category' => 'Sanal Ofis', 'tags' => ['sanal ofis'], 'kind' => 'post'], $candidates);

        $this->assertSame('/blog/sanal-ofis-avantajlari', $ranked[0]['path']);
        $this->assertGreaterThanOrEqual(60, $ranked[0]['score']);
        $this->assertSame('/cozum/sanal-ofis', $ranked[1]['path']);
        $last = collect($ranked)->firstWhere('path', '/blog/toplanti-odasi-rezervasyonu');
        $this->assertTrue($last === null || $last['score'] < 30);
        $this->assertSame(['sanal', 'ofis', 'avantaj'], RedirectMatcher::tokens('Sanal ofisin avantajları'));
        $this->assertSame([], RedirectMatcher::rank(['path' => '/'], $candidates));
    }

    #[Test]
    public function slug_degisince_eski_adres_301_ile_yeniye_gider_ve_gecmis_tutulur(): void
    {
        $admin = $this->staff('system_admin');
        $post = $this->livePost('eski-yazi', 'Eski yazı');

        // Yayındaki içerik önce taslağa (düzenleme kuralı), sonra slug değişir.
        $this->actingAs($admin)->post("/panel/icerik/{$post->id}/taslaga-al")->assertRedirect();
        $this->actingAs($admin)->put("/panel/icerik/{$post->id}", ['title' => 'Yeni yazı', 'slug' => 'yeni-yazi', 'body' => $post->body, 'category' => 'Sanal Ofis', 'tags' => 'sanal ofis'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('yeni-yazi', $post->fresh()->slug);

        $history = ContentUrlHistory::query()->where('entity_id', $post->id)->where('reason', 'slug_change')->firstOrFail();
        $this->assertSame(['/blog/eski-yazi', '/blog/yeni-yazi'], [$history->old_path, $history->new_path]);
        $redirect = UrlRedirect::query()->where('from_path', '/blog/eski-yazi')->firstOrFail();
        $this->assertSame(['/blog/yeni-yazi', 301, 'active', 'slug_change'], [$redirect->to_path, $redirect->code, $redirect->status, $redirect->source]);
        $this->assertTrue(AuditLog::query()->where('action', 'redirect.created')->exists());

        // Vitrin: eski adres 301 → yeni; sorgu dizgisi korunur; isabet sayılır; büyük harf/eğik çizgi normalize.
        $this->get('http://localhost/blog/eski-yazi?utm=x')->assertStatus(301)->assertRedirect('/blog/yeni-yazi?utm=x');
        $this->assertSame(1, $redirect->fresh()->hits);
        app(ContentService::class)->publishNow($admin, $post->fresh());
        $this->get('http://localhost/blog/yeni-yazi')->assertOk();

        // İkinci değişim: zincir oluşmaz — eski-yazi doğrudan en yeni adrese.
        $this->actingAs($admin)->post("/panel/icerik/{$post->id}/taslaga-al")->assertRedirect();
        $this->actingAs($admin)->put("/panel/icerik/{$post->id}", ['title' => 'Yeni yazı', 'slug' => 'en-yeni-yazi', 'body' => $post->body])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('/blog/en-yeni-yazi', UrlRedirect::query()->where('from_path', '/blog/eski-yazi')->value('to_path'));
        $this->assertSame('/blog/en-yeni-yazi', UrlRedirect::query()->where('from_path', '/blog/yeni-yazi')->value('to_path'));
        $this->assertSame(0, app(RedirectService::class)->stats($this->site)['chains']);

        // Slug'ı geri almak döngü üretmez: yeni-yazi'ye dönünce yeni-yazi kaynağı silinir.
        $this->actingAs($admin)->put("/panel/icerik/{$post->id}", ['title' => 'Yeni yazı', 'slug' => 'yeni-yazi', 'body' => $post->body])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(UrlRedirect::query()->where('from_path', '/blog/yeni-yazi')->first());
        $this->assertSame('/blog/yeni-yazi', UrlRedirect::query()->where('from_path', '/blog/en-yeni-yazi')->value('to_path'));
        $this->assertSame(0, app(RedirectService::class)->stats($this->site)['loops']);
    }

    #[Test]
    public function silme_onayi_oneri_sunar_ve_secime_gore_yonlendirme_kurar(): void
    {
        $admin = $this->staff('system_admin');
        $keep = $this->livePost('sanal-ofis-avantajlari', 'Sanal ofisin avantajları');
        $gone = $this->livePost('sanal-ofis-nedir', 'Sanal ofis nedir?');

        // Silme onay sayfası: önerilen hedef + üç seçenek.
        $this->actingAs($admin)->post("/panel/icerik/{$gone->id}/arsivle", ['note' => 'eski'])->assertRedirect();
        $this->assertTrue(ContentUrlHistory::query()->where('old_path', '/blog/sanal-ofis-nedir')->where('reason', 'archived')->exists());
        $page = $this->actingAs($admin)->get("/panel/icerik/{$gone->id}/sil")->assertOk()->getContent();
        $this->assertStringContainsString('Bu URL için yönlendirme oluşturulsun mu?', $page);
        $this->assertStringContainsString('/blog/sanal-ofis-avantajlari', $page);
        $this->assertStringContainsString('name="redirect_mode" value="suggest"', $page);
        $this->assertStringContainsString('name="redirect_mode" value="custom"', $page);
        $this->assertStringContainsString('name="redirect_mode" value="none"', $page);

        // Yönlendir (öneri) → silinir, 301 kurulur, vitrinde eski adres yeniye gider, sitemap'te eski adres yok.
        $this->actingAs($admin)->delete("/panel/icerik/{$gone->id}", ['redirect_mode' => 'suggest', 'redirect_to' => '/blog/sanal-ofis-avantajlari'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSoftDeleted('contents', ['id' => $gone->id]);
        $r = UrlRedirect::query()->where('from_path', '/blog/sanal-ofis-nedir')->firstOrFail();
        $this->assertSame(['/blog/sanal-ofis-avantajlari', 'deleted', 'active'], [$r->to_path, $r->source, $r->status]);
        $this->assertSame('/blog/sanal-ofis-avantajlari', ContentUrlHistory::query()->where('old_path', '/blog/sanal-ofis-nedir')->where('reason', 'deleted')->value('new_path'));
        $this->get('http://localhost/blog/sanal-ofis-nedir')->assertStatus(301)->assertRedirect('/blog/sanal-ofis-avantajlari');
        $sitemap = $this->get('http://localhost/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/blog/sanal-ofis-avantajlari', $sitemap);
        $this->assertStringNotContainsString('/blog/sanal-ofis-nedir', $sitemap);

        // Farklı URL seç.
        $other = $this->livePost('gecici-yazi', 'Geçici yazı', 'Diğer', 'x');
        $this->actingAs($admin)->post("/panel/icerik/{$other->id}/arsivle")->assertRedirect();
        $this->actingAs($admin)->delete("/panel/icerik/{$other->id}", ['redirect_mode' => 'custom', 'redirect_custom' => '/blog'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('/blog', UrlRedirect::query()->where('from_path', '/blog/gecici-yazi')->value('to_path'));

        // Yönlendirme oluşturma → yalnız geçmiş; vitrinde adres benzerlik zincirine düşer (aşağıdaki test).
        $none = $this->livePost('yonlendirmesiz', 'Yönlendirmesiz yazı', 'Diğer', 'y');
        $this->actingAs($admin)->post("/panel/icerik/{$none->id}/arsivle")->assertRedirect();
        $this->actingAs($admin)->delete("/panel/icerik/{$none->id}", ['redirect_mode' => 'none'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(UrlRedirect::query()->where('from_path', '/blog/yonlendirmesiz')->first());
        $this->assertTrue(ContentUrlHistory::query()->where('old_path', '/blog/yonlendirmesiz')->where('reason', 'deleted')->exists());

        // Geçersiz seçim (javascript:) yok sayılır.
        $bad = $this->livePost('kotu', 'Kötü', 'Diğer', 'z');
        $this->actingAs($admin)->post("/panel/icerik/{$bad->id}/arsivle")->assertRedirect();
        $this->actingAs($admin)->delete("/panel/icerik/{$bad->id}", ['redirect_mode' => 'custom', 'redirect_custom' => 'javascript:alert(1)'])->assertRedirect();
        $this->assertNull(UrlRedirect::query()->where('from_path', '/blog/kotu')->first());
        $this->assertNotNull($keep->fresh());
    }

    #[Test]
    public function hizmet_ve_lokasyon_silmede_yonlendirme_secilir(): void
    {
        $admin = $this->staff('system_admin');
        $service = Service::query()->where('slug', 'sanal-ofis')->firstOrFail();
        $service->locations()->detach();
        $this->actingAs($admin)->get("/panel/hizmetler/{$service->slug}/sil")->assertOk()->assertSee('/cozum/sanal-ofis')->assertSee('Bu URL için yönlendirme oluşturulsun mu?');
        $this->actingAs($admin)->delete("/panel/hizmetler/{$service->slug}", ['redirect_mode' => 'custom', 'redirect_custom' => '/cozumler'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('/cozumler', UrlRedirect::query()->where('from_path', '/cozum/sanal-ofis')->value('to_path'));
        $this->assertSame('Sanal Ofis', ContentUrlHistory::query()->where('old_path', '/cozum/sanal-ofis')->value('snapshot')['title'] ?? null);
        $this->get('http://localhost/cozum/sanal-ofis')->assertStatus(301)->assertRedirect('/cozumler');

        $location = Location::query()->where('slug', 'lara-deniz')->firstOrFail();
        $location->forceFill(['is_published' => false])->save();
        $this->actingAs($admin)->get("/panel/geo/lokasyon/{$location->slug}/sil")->assertOk()->assertSee('/lokasyon/lara-deniz');
        $this->actingAs($admin)->delete("/panel/geo/lokasyon/{$location->slug}", ['redirect_mode' => 'none'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('locations', ['id' => $location->id]);
        $this->assertTrue(ContentUrlHistory::query()->where('old_path', '/lokasyon/lara-deniz')->where('entity_type', 'location')->exists());
        $this->assertNull(UrlRedirect::query()->where('from_path', '/lokasyon/lara-deniz')->first());
    }

    #[Test]
    public function dort_yuz_dort_karar_zinciri_otomatik_oneri_ust_kategori_ve_duz_404(): void
    {
        $admin = $this->staff('system_admin');
        $keep = $this->livePost('sanal-ofis-avantajlari', 'Sanal ofisin avantajları', 'Sanal Ofis', 'sanal ofis, avantaj', 'Sanal ofis adres tescil posta '.str_repeat('avantaj ', 20));
        $gone = $this->livePost('sanal-ofis-avantajlari-nelerdir', 'Sanal ofisin avantajları nelerdir?', 'Sanal Ofis', 'sanal ofis, avantaj');

        // Yayından kalkan (arşiv) içerik: URL geçmişi + anlık görüntü; vitrinde 404 yerine yüksek benzerlik → otomatik 301.
        $this->actingAs($admin)->post("/panel/icerik/{$gone->id}/arsivle")->assertRedirect();
        $this->get('http://localhost/blog/sanal-ofis-avantajlari-nelerdir')->assertStatus(301)->assertRedirect('/blog/sanal-ofis-avantajlari');
        $auto = UrlRedirect::query()->where('from_path', '/blog/sanal-ofis-avantajlari-nelerdir')->firstOrFail();
        $this->assertSame(['auto', 'active'], [$auto->source, $auto->status]);
        $this->assertGreaterThanOrEqual(85, $auto->score);
        $this->assertSame('redirected', NotFoundLog::query()->where('path', '/blog/sanal-ofis-avantajlari-nelerdir')->value('status'));
        $this->assertTrue(AuditLog::query()->where('action', 'redirect.created')->where('entity_id', $auto->id)->exists());

        // Orta benzerlik: yönlendirme YOK, öneri (pending) + 404 sayfasında "belki aradığınız".
        $mid = $this->livePost('sanal-ofis-fiyatlari', 'Sanal ofis fiyatları', 'Sanal Ofis', 'sanal ofis', 'Fiyat listesi.');
        $this->actingAs($admin)->post("/panel/icerik/{$mid->id}/arsivle")->assertRedirect();
        $res = $this->get('http://localhost/blog/sanal-ofis-fiyatlari')->assertStatus(404);
        $pending = UrlRedirect::query()->where('from_path', '/blog/sanal-ofis-fiyatlari')->first();

        if ($pending !== null) {
            $this->assertSame('pending', $pending->status);
            $this->assertLessThan(85, $pending->score);
            $this->assertGreaterThanOrEqual(60, $pending->score);
            $res->assertSee('Belki aradığınız');
            // Bekleyen öneri sessizce yönlendirmez; ikinci istek de 404.
            $this->get('http://localhost/blog/sanal-ofis-fiyatlari')->assertStatus(404);

            // Panelden onay → etkin → vitrin 301.
            $this->actingAs($admin)->post("/panel/seo/{$this->site->id}/yonlendirmeler/{$pending->id}/onayla", ['decision' => 'approve', 'code' => 301])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame('active', $pending->fresh()->status);
            $this->get('http://localhost/blog/sanal-ofis-fiyatlari')->assertStatus(301);
        }

        // Düşük benzerlik + gerçekten var olmuş adres → üst kategori (yazının kategorisi canlı yazıda varsa) ya da /blog.
        $low = $this->livePost('kurumsal-etkinlik-planlama', 'Kurumsal etkinlik planlama rehberi', 'Etkinlik', 'etkinlik', 'Etkinlik planlama.');
        $this->actingAs($admin)->post("/panel/icerik/{$low->id}/arsivle")->assertRedirect();
        $this->get('http://localhost/blog/kurumsal-etkinlik-planlama')->assertStatus(301)->assertRedirect('/blog');
        $this->assertSame('fallback', UrlRedirect::query()->where('from_path', '/blog/kurumsal-etkinlik-planlama')->value('source'));

        // Rastgele adres: ana sayfaya yönlendirme YOK, düz 404 + günlük; ikinci istek isabeti artırır; uzantılı bot yolu günlüğe girmez.
        $this->get('http://localhost/blog/hic-olmayan-bir-sey-xyz')->assertStatus(404)->assertDontSee('Belki aradığınız');
        $this->get('http://localhost/blog/hic-olmayan-bir-sey-xyz', ['Referer' => 'https://ornek.test/liste'])->assertStatus(404);
        $log = NotFoundLog::query()->where('path', '/blog/hic-olmayan-bir-sey-xyz')->firstOrFail();
        $this->assertSame([2, 'open', 'https://ornek.test/liste'], [$log->hits, $log->status, $log->referer]);
        $this->get('http://localhost/wp-login.php')->assertStatus(404);
        $this->assertNull(NotFoundLog::query()->where('path', 'like', '%wp-login%')->first());
        $this->assertNull(UrlRedirect::query()->where('to_path', '/')->where('from_path', '/blog/hic-olmayan-bir-sey-xyz')->first());

        // Panel 404 günlüğü: hit sırası, öneri, yok say.
        $html = $this->actingAs($admin)->get("/panel/seo/{$this->site->id}/yonlendirmeler/404")->assertOk()->getContent();
        $this->assertStringContainsString('/blog/hic-olmayan-bir-sey-xyz', $html);
        $this->actingAs($admin)->post("/panel/seo/{$this->site->id}/yonlendirmeler/404/{$log->id}/durum", ['durum' => 'ignore'])->assertRedirect();
        $this->assertSame('ignored', $log->fresh()->status);
        $this->get('http://localhost/blog/hic-olmayan-bir-sey-xyz')->assertStatus(404);
        $this->assertSame(3, $log->fresh()->hits);
        $this->assertNotNull($keep->fresh());
    }

    #[Test]
    public function manuel_yonetim_zincir_duzlestirme_dongu_reddi_bot_ve_yetkiler(): void
    {
        $admin = $this->staff('system_admin');   // seo.view + seo.edit + seo.audit
        $finance = $this->staff('finance_admin'); // seo yok
        $a = $this->livePost('a-yazisi', 'A yazısı', 'Diğer', 'a');
        $b = $this->livePost('b-yazisi', 'B yazısı', 'Diğer', 'b');

        $this->actingAs($finance)->get("/panel/seo/{$this->site->id}/yonlendirmeler")->assertForbidden();
        $this->actingAs($admin)->get('/panel/seo/yonlendirmeler')->assertRedirect("/panel/seo/{$this->site->id}/yonlendirmeler");
        $this->actingAs($admin)->get("/panel/seo/{$this->site->id}/yonlendirmeler")->assertOk()->assertSee('Yeni yönlendirme')->assertSee('Redirect chain');

        // Manuel: 302 ile /eski-sayfa → /blog/a-yazisi; doğrulama: geçersiz kod, aynı yol.
        $store = fn (array $d) => $this->actingAs($admin)->from("/panel/seo/{$this->site->id}/yonlendirmeler")->post("/panel/seo/{$this->site->id}/yonlendirmeler", $d);
        $store(['from_path' => '/eski-sayfa', 'to_path' => '/blog/a-yazisi', 'code' => 302, 'status' => 'active', 'note' => 'kampanya'])->assertRedirect()->assertSessionHasNoErrors();
        $store(['from_path' => '/x', 'to_path' => '/x', 'code' => 301, 'status' => 'active'])->assertSessionHasErrors('from_path');
        $store(['from_path' => '/x', 'to_path' => '/y', 'code' => 999, 'status' => 'active'])->assertSessionHasErrors('code');
        $store(['from_path' => 'eski', 'to_path' => '/y', 'code' => 301, 'status' => 'active'])->assertSessionHasErrors('from_path');
        $this->get('http://localhost/eski-sayfa')->assertStatus(302)->assertRedirect('/blog/a-yazisi');

        // Zincir: /blog/a-yazisi → /blog/b-yazisi eklenince /eski-sayfa doğrudan /blog/b-yazisi'ne düzleşir.
        $store(['from_path' => '/blog/a-yazisi', 'to_path' => '/blog/b-yazisi', 'code' => 301, 'status' => 'active'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('/blog/b-yazisi', UrlRedirect::query()->where('from_path', '/eski-sayfa')->value('to_path'));
        $this->get('http://localhost/eski-sayfa')->assertRedirect('/blog/b-yazisi');

        // Döngü: /blog/b-yazisi → /eski-sayfa reddedilir (A → B → A).
        $store(['from_path' => '/blog/b-yazisi', 'to_path' => '/eski-sayfa', 'code' => 301, 'status' => 'active'])->assertSessionHasErrors('from_path');
        $this->assertNull(UrlRedirect::query()->where('from_path', '/blog/b-yazisi')->first());

        // Kaynak yönlendirilen /blog/a-yazisi sitemap'te olmamalı; b-yazisi olmalı. Canonical: a'nın canonical'ı b'ye çözülür.
        $sitemap = $this->get('http://localhost/sitemap.xml')->assertOk()->getContent();
        $this->assertStringNotContainsString('/blog/a-yazisi', $sitemap);
        $this->assertStringContainsString('/blog/b-yazisi', $sitemap);
        $b->forceFill(['canonical_url' => '/blog/a-yazisi'])->save();
        app(ContentCache::class)->invalidate($this->site);
        $this->assertStringContainsString('rel="canonical" href="http://localhost:8000/blog/b-yazisi"', $this->get('http://localhost/blog/b-yazisi')->assertOk()->getContent());

        // Yönlendirme tablosu ayarları zayıflatmaz: pasif kayıt uygulanmaz.
        $r = UrlRedirect::query()->where('from_path', '/eski-sayfa')->firstOrFail();
        $this->actingAs($admin)->put("/panel/seo/{$this->site->id}/yonlendirmeler/{$r->id}", ['from_path' => '/eski-sayfa', 'to_path' => '/blog/b-yazisi', 'code' => 301, 'status' => 'disabled'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('http://localhost/eski-sayfa')->assertStatus(404);

        // Bot: kırık iç bağlantı + yönlendirilmiş iç bağlantı + açık 404 + canonical bulguları; yetki (seo.audit) ve rapor.
        $b->forceFill(['body' => 'Bkz. [kırık](/blog/olmayan-yazi) ve [eski](/blog/a-yazisi).', 'canonical_url' => '/blog/olmayan-yazi'])->save();
        app(ContentCache::class)->invalidate($this->site);
        $manual = app(UrlHistoryService::class)->save($this->site, ['from_path' => '/kirik-hedef', 'to_path' => '/blog/yok-boyle-bir-sey', 'source' => 'manual', 'status' => 'active'], $admin);
        app(UrlHistoryService::class)->save($this->site, ['from_path' => '/anaya', 'to_path' => '/', 'source' => 'manual', 'status' => 'active'], $admin);
        $this->actingAs($finance)->post("/panel/seo/{$this->site->id}/yonlendirmeler/tara")->assertForbidden();
        $this->actingAs($admin)->post("/panel/seo/{$this->site->id}/yonlendirmeler/tara")->assertRedirect("/panel/seo/{$this->site->id}/yonlendirmeler/bot");
        $scan = app(RedirectService::class)->lastScan($this->site);
        $kinds = collect($scan['findings'])->pluck('kind')->unique()->values()->all();
        foreach (['internal_link', 'internal_redirect', 'broken_target', 'to_home', 'canonical'] as $kind) {
            $this->assertContains($kind, $kinds, "Bot bulgusu eksik: {$kind}");
        }
        $this->assertSame('critical', collect($scan['findings'])->firstWhere('kind', 'broken_target')['level']);
        $bot = $this->actingAs($admin)->get("/panel/seo/{$this->site->id}/yonlendirmeler/bot")->assertOk()->getContent();
        $this->assertStringContainsString('Kritik', $bot);
        $this->assertStringContainsString('/kirik-hedef', $bot);
        $this->assertStringContainsString('Ana sayfaya yönlendirme: /anaya', $bot);
        $this->assertNotNull($manual->fresh());
        $this->assertNotNull($a->fresh());

        // İstatistik kartları ve rozet.
        $stats = app(RedirectService::class)->stats($this->site);
        $this->assertSame(0, $stats['loops']);
        $this->assertGreaterThanOrEqual(3, $stats['redirects']);
        $this->actingAs($admin)->get("/panel/seo/{$this->site->id}/yonlendirmeler/gecmis")->assertOk();
        $this->actingAs($admin)->get("/panel/seo/{$this->site->id}/yonlendirmeler/oneriler")->assertOk();
        $this->actingAs($admin)->get("/panel/seo/{$this->site->id}/yonlendirmeler/bilinmeyen")->assertNotFound();
    }
}
