<?php

namespace Tests\Feature\Panel;

use App\Enums\ContentStatus;
use App\Events\ContentPublicationChanged;
use App\Models\AuditLog;
use App\Models\ConsentRecord;
use App\Models\Content;
use App\Models\Lead;
use App\Models\LegalDocumentVersion;
use App\Models\Media;
use App\Models\SiteChromeVersion;
use App\Models\Website;
use App\Services\LeadService;
use App\Services\LegalDocumentService;
use App\Services\SiteBuilderService;
use App\Services\SiteChromeService;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 61a — Global header/footer: panelden yayın → tüm sayfalarda; taslak önizleme ziyaretçiye görünmez; sürüm +
 * geri alma; temizleme (javascript:, geçersiz renk, yabancı medya düşer); yetki (yayın website.manage); bülten
 * formu lead üretir; görsel editör yayını header/footer taslağını canlıya alır; canlı düzenleme çerçevesi.
 */
class SiteChromeTest extends TestCase
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

    private function media(Website $site, string $name): Media
    {
        return Media::query()->create(['website_id' => $site->id, 'disk' => 'public', 'path' => 'media/'.$site->id.'/'.$name.'.png', 'original_name' => $name.'.png', 'mime_type' => 'image/png', 'size_bytes' => 10, 'width' => 400, 'height' => 120, 'checksum_sha256' => hash('sha256', $name), 'status' => 'approved']);
    }

    private function publishPage(string $slug, string $title): Content
    {
        $page = Content::create(['website_id' => $this->site->id, 'kind' => 'page', 'slug' => $slug, 'title' => $title, 'excerpt' => 'Elli karakterden uzun bir özet metni; meta açıklama olarak yeterli uzunlukta.', 'body' => str_repeat('Metin. ', 60)]);
        $page->forceFill(['status' => ContentStatus::PUBLISHED, 'published_at' => now()->subMinute(), 'show_in_nav' => false])->save();

        return $page;
    }

    #[Test]
    public function header_yayini_tum_sayfalarda_uygulanir_taslak_gorunmez_surum_ve_geri_alma_calisir(): void
    {
        $admin = $this->staff('system_admin');
        $ops = $this->staff('operations_admin'); // content.edit var, website.manage yok
        $finance = $this->staff('finance_admin');
        $logo = $this->media($this->site, 'logo');
        $foreign = Website::create(['organization_id' => $this->organization('Acme')->id, 'name' => 'Acme', 'slug' => 'acme', 'domain' => 'acme.example']);
        $foreignLogo = $this->media($foreign, 'yabanci');

        $this->actingAs($finance)->get('/panel/ayarlar/header')->assertForbidden();
        $this->actingAs($admin)->get('/panel/ayarlar/header')->assertOk()->assertSee('Header ayarları')->assertSee('tüm sitede uygulanacaktır')->assertSee('chrome-editor.js');
        $this->actingAs($admin)->get('/panel/ayarlar')->assertOk()->assertSee('Entegrasyon merkezi')->assertSee('Header');

        $payload = ['website' => $this->site->id, 'note' => 'Mega menü', 'c' => [
            'logo_media_id' => $logo->id, 'logo_height' => 40, 'height' => 90, 'sticky' => 0, 'transparent' => 0, 'show_login' => 1, 'show_phone' => 1, 'active_style' => 'pill',
            'menu' => [
                ['label' => 'Çözümler', 'href' => '/cozumler', 'mega' => 1, 'children' => [['label' => 'Sanal Ofis', 'href' => '/cozum/sanal-ofis', 'description' => 'Tescil adresi'], ['label' => 'Kötü', 'href' => 'javascript:alert(1)']]],
                ['label' => 'Yazılar', 'href' => '/blog'],
                ['label' => 'Boş', 'href' => ''],
            ],
            'cta' => ['label' => 'Hemen Başvur', 'href' => '#teklif', 'style' => 'ghost'],
            'colors' => ['bg' => '#FFFFFF', 'text' => 'kirmizi', 'hover' => '#1F5B45', 'active' => ''],
            'social' => [['network' => 'instagram', 'url' => 'https://instagram.com/ofisvio'], ['network' => 'x', 'url' => 'http://insecure'], ['network' => 'bilinmeyen', 'url' => 'https://a.b']],
        ]];

        // Yayın yetkisi: content.edit yetmez; website.manage yayınlar.
        $this->actingAs($ops)->post('/panel/ayarlar/header/yayinla', $payload)->assertForbidden();
        $this->actingAs($admin)->post('/panel/ayarlar/header/yayinla', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $config = $this->site->fresh()->header_config;
        $this->assertSame($logo->id, $config['logo_media_id']);
        $this->assertCount(2, $config['menu'], 'href boş öğe düşer');
        $this->assertCount(1, $config['menu'][0]['children'], 'javascript: alt öğe düşer');
        $this->assertTrue($config['menu'][0]['mega']);
        $this->assertSame(['bg' => '#ffffff', 'text' => '', 'hover' => '#1f5b45', 'active' => ''], $config['colors'], 'geçersiz renk düşer, hex küçültülür');
        $this->assertSame([['network' => 'instagram', 'url' => 'https://instagram.com/ofisvio']], $config['social'], 'http ve bilinmeyen ağ düşer');
        $this->assertFalse($config['sticky']);
        $this->assertSame(0, SiteChromeVersion::count(), 'ilk yayında önceki yapılandırma yok → sürüm düşmez');
        $this->assertTrue(AuditLog::query()->where('action', 'site.chrome_published')->exists());

        // Tüm sayfalarda: ana sayfa, hizmet, blog.
        foreach (['/', '/cozum/sanal-ofis', '/blog'] as $path) {
            $html = $this->get('http://localhost'.$path)->assertOk()->getContent();
            $this->assertStringContainsString('Hemen Başvur', $html, $path);
            $this->assertStringContainsString('nav-dropdown--mega', $html, $path);
            $this->assertStringContainsString('Tescil adresi', $html, $path);
            $this->assertStringContainsString('site-header--static', $html, $path);
            $this->assertStringContainsString('--hdr-h:90px', $html, $path);
            $this->assertStringContainsString('--hdr-bg:#ffffff', $html, $path);
            $this->assertStringContainsString($logo->urlFor(400), $html, $path);
            $this->assertStringContainsString('instagram.com/ofisvio', $html, $path);
            $this->assertStringNotContainsString('javascript:', $html);
        }

        $this->assertStringContainsString('class="is-active"', $this->get('http://localhost/blog')->getContent(), 'aktif menü');

        // Yabancı sitenin medyası kabul edilmez.
        $this->actingAs($admin)->post('/panel/ayarlar/header/yayinla', ['website' => $this->site->id, 'c' => ['logo_media_id' => $foreignLogo->id, 'menu' => $payload['c']['menu'], 'cta' => $payload['c']['cta']]])->assertRedirect();
        $this->assertNull($this->site->fresh()->header_config['logo_media_id']);
        $this->assertSame(1, SiteChromeVersion::query()->where('area', 'header')->count(), 'ikinci yayın önceki yapılandırmayı sürüm olarak sakladı');

        // Taslak önizleme: ziyaretçi görmez; imzalı önizleme görür; taslak atılır.
        $this->actingAs($ops)->post('/panel/ayarlar/header/onizle', ['website' => $this->site->id, 'c' => ['menu' => [['label' => 'Taslak Menü', 'href' => '/blog']], 'cta' => ['label' => 'Taslak CTA', 'href' => '#teklif']]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(app(SiteChromeService::class)->hasDraft($this->site->fresh(), 'header'));
        $this->assertStringNotContainsString('Taslak CTA', $this->get('http://localhost/')->getContent());
        $this->assertStringContainsString('Taslak CTA', $this->actingAs($admin)->get(app(SiteBuilderService::class)->previewUrl($this->site->fresh()))->assertOk()->getContent());
        $this->actingAs($admin)->get('/panel/ayarlar/header')->assertOk()->assertSee('Yayınlanmamış taslak var')->assertSee('Taslak CTA');
        $this->actingAs($ops)->post('/panel/ayarlar/header/taslak-at', ['website' => $this->site->id])->assertRedirect();
        $this->assertFalse(app(SiteChromeService::class)->hasDraft($this->site->fresh(), 'header'));

        // Geri alma: sürüm 1 (logo'lu) yeniden yayınlanır; mevcut yeni sürüm olur.
        $version = SiteChromeVersion::query()->where('area', 'header')->firstOrFail();
        $this->actingAs($ops)->post("/panel/ayarlar/header/surum/{$version->id}", ['website' => $this->site->id])->assertForbidden();
        $this->actingAs($admin)->post("/panel/ayarlar/header/surum/{$version->id}", ['website' => $this->site->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($logo->id, $this->site->fresh()->header_config['logo_media_id']);
        $this->assertSame(2, SiteChromeVersion::query()->where('area', 'header')->count());
    }

    #[Test]
    public function footer_yayini_kolonlar_yasal_baglantilar_bulten_ve_editor_yayini_ile_taslak(): void
    {
        $admin = $this->staff('system_admin');
        $kvkk = $this->publishPage('kvkk-bilgilendirme', 'KVKK Bilgilendirme');
        $terms = $this->publishPage('site-kullanim-sartlari', 'Site Kullanım Şartları');
        $other = $this->publishPage('hakkimizda', 'Hakkımızda');

        // Seçim yokken tüm yayındaki sayfalar alt şeritte (eski davranış).
        $this->assertStringContainsString('Hakkımızda', $this->get('http://localhost/')->getContent());

        $this->actingAs($admin)->post('/panel/ayarlar/footer/yayinla', ['website' => $this->site->id, 'c' => [
            'description' => 'Konya merkezli esnek ofis operatörü.', 'show_contact' => 1, 'show_hours' => 0, 'show_address' => 1, 'show_location' => 1,
            'columns' => [['title' => 'Hizmetler', 'items' => [['label' => 'Sanal Ofis', 'href' => '/cozum/sanal-ofis'], ['label' => 'Coworking', 'href' => '/cozum/coworking']]], ['title' => 'Kurumsal', 'items' => [['label' => 'Blog', 'href' => '/blog']]], ['title' => 'Boş kolon', 'items' => []]],
            'newsletter' => ['enabled' => 1, 'title' => 'Bültenimiz', 'text' => 'Ayda bir e-posta.'],
            'legal' => ['kvkk' => $kvkk->id, 'terms' => $terms->id, 'privacy' => 999999],
            'copyright' => '© 2026 Ofisvio A.Ş. Tüm hakları saklıdır.', 'bottom_text' => 'Konya',
            'cta' => ['label' => 'Teklif al', 'href' => '#teklif'],
        ]])->assertRedirect()->assertSessionHasNoErrors();

        $config = $this->site->fresh()->footer_config;
        $this->assertCount(2, $config['columns'], 'boş kolon düşer');
        $this->assertNull($config['legal']['privacy'], 'olmayan sayfa düşer');

        // Audit F-07 (KVKK): footer yayını seçili yasal sayfalar için sürüm açar (kvkk v1, terms v1); değişmeyen gövde yeni sürüm açmaz;
        // gövde değişip içerik yeniden yayınlanınca v2; vitrin rızası o anki sürüme ve özete bağlanır; audit değer değil sürüm/özet yazar.
        $legal = app(LegalDocumentService::class);
        $this->assertSame([1, 1], [$legal->current($this->site->fresh(), 'kvkk')?->version, $legal->current($this->site->fresh(), 'terms')?->version]);
        $this->assertNull($legal->current($this->site->fresh(), 'privacy'));
        $this->assertSame(hash('sha256', (string) $kvkk->body), $legal->current($this->site->fresh(), 'kvkk')?->content_hash);
        $this->assertSame(0, $legal->syncFromFooter($admin, $this->site->fresh(), 'tekrar yayın'), 'gövde değişmedi → yeni sürüm yok');
        $this->assertSame(2, LegalDocumentVersion::query()->count());
        $kvkk->forceFill(['body' => $kvkk->body."\n\nEk madde: veri işleme süresi güncellendi."])->save();
        event(new ContentPublicationChanged($kvkk->fresh(), true));
        $this->assertSame(2, $legal->current($this->site->fresh(), 'kvkk')?->version);
        $this->assertSame(3, LegalDocumentVersion::query()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'legal.version_published')->count() >= 3);
        $lead = app(LeadService::class)->capture(['kind' => 'quote', 'name' => 'Rıza Test', 'email' => 'riza@example.com'], ['ip' => '203.0.113.7', 'user_agent' => 'phpunit']);
        $consent = ConsentRecord::query()->where('subject_type', 'lead')->where('subject_id', $lead->id)->firstOrFail();
        $this->assertSame(['kvkk', 2, hash('sha256', (string) $kvkk->fresh()->body), '203.0.113.7'], [$consent->kind, $consent->version?->version, $consent->content_hash, $consent->ip]);
        $this->actingAs($admin)->get('/panel/ayarlar/footer')->assertOk()->assertSee('Yasal metin sürümleri')->assertSee('v2');

        foreach (['/', '/cozum/sanal-ofis', '/blog', '/lokasyonlar'] as $path) {
            $html = $this->get('http://localhost'.$path)->assertOk()->getContent();
            $this->assertStringContainsString('Konya merkezli esnek ofis operatörü.', $html, $path);
            $this->assertStringContainsString('Kurumsal', $html, $path);
            $this->assertStringContainsString('Bültenimiz', $html, $path);
            $this->assertStringContainsString('© 2026 Ofisvio A.Ş. Tüm hakları saklıdır. · Konya', $html, $path);
            $this->assertStringContainsString('KVKK Bilgilendirme', $html, $path);
            $this->assertStringContainsString('Site Kullanım Şartları', $html, $path);
            $this->assertStringNotContainsString('>Hakkımızda<', $html, 'seçilmeyen sayfa alt şeritte yok');
        }

        // Bülten: lead kind=newsletter; bot tuzağı doluysa reddedilir.
        $this->post('http://localhost/bulten', ['email' => 'abone@example.com', 'consent' => 1])->assertRedirect()->assertSessionHas('newsletter_sent');
        $lead = Lead::query()->where('kind', 'newsletter')->firstOrFail();
        $this->assertSame('abone@example.com', $lead->email);
        $this->assertNotNull($lead->consented_at);
        $this->post('http://localhost/bulten', ['email' => 'bot@example.com', 'consent' => 1, 'website' => 'x'])->assertSessionHasErrors('website');
        $this->assertSame(1, Lead::query()->where('kind', 'newsletter')->count());
        $this->actingAs($admin)->get('/panel/talepler')->assertOk()->assertSee('Bülten');

        // Görsel editör yayını: footer taslağı da canlıya geçer (SiteBuilderService::publish → publishGlobals).
        app(SiteChromeService::class)->saveDraft($admin, $this->site->fresh(), 'footer', ['description' => 'Editörden yayınlanan açıklama', 'columns' => $config['columns']]);
        $this->assertStringNotContainsString('Editörden yayınlanan açıklama', $this->get('http://localhost/')->getContent());
        app(SiteBuilderService::class)->publish($admin, $this->site->fresh(), 'test');
        $this->assertStringContainsString('Editörden yayınlanan açıklama', $this->get('http://localhost/')->getContent());
        $this->assertNull($this->site->fresh()->builder_globals);
        $this->assertSame(1, SiteChromeVersion::query()->where('area', 'footer')->count());

        // Canlı düzenleme: mod açıkken header/footer alan işareti ve bar bağlantıları; ziyaretçide yok.
        $this->assertStringNotContainsString('data-le-area', $this->get('http://localhost/')->getContent());
        $this->actingAs($admin)->post('/panel/canli/mod', ['on' => 1, 'return' => '/'])->assertRedirect('/');
        $html = $this->actingAs($admin)->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('data-le-area="header"', $html);
        $this->assertStringContainsString('data-le-area="footer"', $html);
        $this->assertStringContainsString('/panel/ayarlar/footer?return=%2F', $html);
        $this->actingAs($admin)->get('/panel/operasyon')->assertOk()->assertSee('Header &amp; footer', false);
    }
}
