<?php

namespace Tests\Feature\Panel;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Website;
use App\Services\ContentCache;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 10 — Menü yönetimi: sıra + görünürlük; yalnız yayındaki sayfalar menüde;
 * "Yazılar" yalnız yazı varsa; müşteri ve personel aynı servisi kullanır;
 * yabancı site/sayfa etkilenmez.
 */
class SiteMenuTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(WebsiteSeeder::class);
    }

    private function page(Website $site, string $title, bool $live = true): Content
    {
        $c = Content::create(['website_id' => $site->id, 'kind' => 'page', 'slug' => str($title)->slug()->toString(), 'title' => $title, 'body' => 'Gövde.']);

        if ($live) {
            $c->forceFill(['status' => ContentStatus::PUBLISHED, 'published_at' => now()->subDay()])->save();
        }

        return $c;
    }

    #[Test]
    public function sahip_menuyu_siralar_gizler_ve_site_menusu_buna_uyar(): void
    {
        $acme = $this->organization('Acme');
        $acmeCo = $this->company($acme, 'Acme A.Ş.');
        $site = Website::create(['organization_id' => $acme->id, 'name' => 'Acme', 'slug' => 'acme', 'domain' => 'acme.example']);
        $owner = $this->owner($acme, $acmeCo);

        $about = $this->page($site, 'Hakkımızda');
        $contact = $this->page($site, 'İletişim');
        $services = $this->page($site, 'Hizmetler');
        $draft = $this->page($site, 'Taslak sayfa', false);

        // Varsayılan: alfabetik, yazı yoksa "Yazılar" bağlantısı yok.
        $html = $this->get('http://acme.example/')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/Hakkımızda.*Hizmetler.*İletişim/s', $this->nav($html));
        $this->assertStringNotContainsString('Taslak sayfa', $this->nav($html));
        $this->assertStringNotContainsString('>Yazılar<', $this->nav($html));

        $menu = "/panel/sirketler/{$acmeCo->id}/site/menu/{$site->id}";
        $this->actingAs($owner)->withContext($acme)->get("/panel/sirketler/{$acmeCo->id}/site")->assertOk()->assertSee($menu, false);
        $this->actingAs($owner)->withContext($acme)->get($menu)->assertOk()->assertSee('Site menüsü')->assertSee('Taslak sayfa');

        $this->actingAs($owner)->withContext($acme)->put($menu, ['nav' => [
            $about->id => ['order' => 2, 'show' => 1],
            $contact->id => ['order' => 1, 'show' => 1],
            $services->id => ['order' => '', 'show' => 0],
            $draft->id => ['order' => 3, 'show' => 1],
        ]])->assertRedirect($menu)->assertSessionHasNoErrors();

        $html = $this->get('http://acme.example/')->assertOk()->getContent();
        $nav = $this->nav($html);
        $this->assertMatchesRegularExpression('/İletişim.*Hakkımızda/s', $nav);
        $this->assertStringNotContainsString('Hizmetler', $nav, 'Gizlenen sayfa menüde yok.');
        $this->assertStringNotContainsString('Taslak sayfa', $nav, 'Taslak, sırası olsa da yayına girene kadar menüde yok.');
        $this->get('http://acme.example/hizmetler')->assertOk(); // gizli ama erişilebilir

        // Menü yapısal alandır: içerik durumu ve revizyonu değişmez.
        $this->assertSame(ContentStatus::PUBLISHED, $services->fresh()->status);
        $this->assertFalse($services->fresh()->show_in_nav);

        // Yazı yayınlanınca "Yazılar" bağlantısı gelir.
        $post = Content::create(['website_id' => $site->id, 'kind' => 'post', 'slug' => 'ilk', 'title' => 'İlk yazı', 'body' => 'x']);
        $post->forceFill(['status' => ContentStatus::PUBLISHED, 'published_at' => now()])->save();
        app(ContentCache::class)->invalidate($site);
        $this->assertStringContainsString('>Yazılar<', $this->nav($this->get('http://acme.example/')->getContent()));

        // Doğrulama: sıra aralık dışı reddedilir.
        $this->actingAs($owner)->withContext($acme)->from($menu)->put($menu, ['nav' => [$about->id => ['order' => 5000, 'show' => 1]]])->assertSessionHasErrors('nav.'.$about->id.'.order');
    }

    #[Test]
    public function yabanci_site_ve_sayfa_etkilenmez_personel_de_duzenler(): void
    {
        $acme = $this->organization('Acme');
        $acmeCo = $this->company($acme, 'Acme A.Ş.');
        $site = Website::create(['organization_id' => $acme->id, 'name' => 'Acme', 'slug' => 'acme', 'domain' => 'acme.example']);
        $owner = $this->owner($acme, $acmeCo);
        $default = Website::query()->default()->firstOrFail();
        $ofisvioPage = $this->page($default, 'Ofisvio Hakkında');
        $mine = $this->page($site, 'Hakkımızda');

        // Yabancı site 404; kendi sitesinin formunda yabancı sayfa id'si sessizce atlanır.
        $this->actingAs($owner)->withContext($acme)->get("/panel/sirketler/{$acmeCo->id}/site/menu/{$default->id}")->assertNotFound();
        $this->actingAs($owner)->withContext($acme)->put("/panel/sirketler/{$acmeCo->id}/site/menu/{$default->id}", ['nav' => [$ofisvioPage->id => ['order' => 1, 'show' => 0]]])->assertNotFound();
        $this->actingAs($owner)->withContext($acme)->put("/panel/sirketler/{$acmeCo->id}/site/menu/{$site->id}", ['nav' => [
            $ofisvioPage->id => ['order' => 1, 'show' => 0],
            $mine->id => ['order' => 1, 'show' => 1],
        ]])->assertRedirect();
        $this->assertTrue($ofisvioPage->fresh()->show_in_nav);
        $this->assertNull($ofisvioPage->fresh()->nav_order);
        $this->assertSame(1, $mine->fresh()->nav_order);

        // company_admin (content.edit) düzenler; rolsüz üye giremez.
        $admin = $this->member($acme);
        $this->grantRole($admin, 'company_admin', ['company_id' => $acmeCo->id]);
        $this->actingAs($admin)->withContext($acme)->get("/panel/sirketler/{$acmeCo->id}/site/menu/{$site->id}")->assertOk();
        $this->actingAs($this->member($acme))->withContext($acme)->get("/panel/sirketler/{$acmeCo->id}/site/menu/{$site->id}")->assertForbidden();

        // Personel: site seçiciyle aynı ekran.
        $staff = $this->staff('operations_admin');
        $this->actingAs($staff)->get('/panel/icerik/menu?website='.$site->id)->assertOk()->assertSee('Hakkımızda')->assertDontSee('Ofisvio Hakkında');
        $this->actingAs($staff)->put("/panel/icerik/menu/{$site->id}", ['nav' => [$mine->id => ['order' => 7, 'show' => 1]]])->assertRedirect();
        $this->assertSame(7, $mine->fresh()->nav_order);
        $this->actingAs($owner)->withContext($acme)->get('/panel/icerik/menu')->assertForbidden();
    }

    #[Test]
    public function tema_secimi_html_data_theme_olarak_basilir(): void
    {
        $acme = $this->organization('Acme');
        $acmeCo = $this->company($acme, 'Acme A.Ş.');
        $site = Website::create(['organization_id' => $acme->id, 'name' => 'Acme', 'slug' => 'acme', 'domain' => 'acme.example']);
        $owner = $this->owner($acme, $acmeCo);
        $default = Website::query()->default()->firstOrFail();

        $this->get('http://acme.example/')->assertOk()->assertSee('<html lang="tr" data-theme="kum">', false);
        $this->actingAs($owner)->withContext($acme)->get("/panel/sirketler/{$acmeCo->id}/site/menu/{$site->id}")->assertOk()->assertSee('Temayı uygula');

        $this->actingAs($owner)->withContext($acme)->put("/panel/sirketler/{$acmeCo->id}/site/tema/{$site->id}", ['theme' => 'gece'])->assertRedirect();
        $this->assertSame('gece', $site->fresh()->theme);
        $this->get('http://acme.example/')->assertOk()->assertSee('data-theme="gece"', false);
        $this->get('http://localhost/')->assertOk()->assertSee('data-theme="kum"', false); // Ofisvio vitrini etkilenmez

        // Bilinmeyen tema reddedilir; yabancı site 404.
        $this->actingAs($owner)->withContext($acme)->from("/panel/sirketler/{$acmeCo->id}/site/menu/{$site->id}")
            ->put("/panel/sirketler/{$acmeCo->id}/site/tema/{$site->id}", ['theme' => 'neon'])->assertSessionHasErrors('theme');
        $this->actingAs($owner)->withContext($acme)->put("/panel/sirketler/{$acmeCo->id}/site/tema/{$default->id}", ['theme' => 'gece'])->assertNotFound();
        $this->assertSame('kum', $default->fresh()->theme);

        // Personel: site formundan ve menü ekranından.
        $admin = $this->staff('system_admin');
        $this->actingAs($admin)->put("/panel/websiteler/{$site->id}", ['name' => 'Acme', 'domain' => 'acme.example', 'organization_id' => $acme->id, 'theme' => 'deniz'])->assertRedirect();
        $this->assertSame('deniz', $site->fresh()->theme);
        $this->actingAs($admin)->put("/panel/icerik/tema/{$site->id}", ['theme' => 'kum'])->assertRedirect();
        $this->assertSame('kum', $site->fresh()->theme);
    }

    #[Test]
    public function sayfa_disi_baglantilar_menuye_eklenir_ve_dogrulanir(): void
    {
        $acme = $this->organization('Acme');
        $acmeCo = $this->company($acme, 'Acme A.Ş.');
        $site = Website::create(['organization_id' => $acme->id, 'name' => 'Acme', 'slug' => 'acme', 'domain' => 'acme.example']);
        $owner = $this->owner($acme, $acmeCo);
        $this->page($site, 'Hakkımızda');
        $url = "/panel/sirketler/{$acmeCo->id}/site/baglantilar/{$site->id}";
        $menu = "/panel/sirketler/{$acmeCo->id}/site/menu/{$site->id}";

        $this->actingAs($owner)->withContext($acme)->put($url, ['links' => "Randevu | https://calendly.com/acme\nBize yazın | mailto:info@acme.example\nKampanya | /kampanya"])->assertRedirect($menu)->assertSessionHasNoErrors();
        $nav = $this->nav($this->get('http://acme.example/')->assertOk()->getContent());
        $this->assertMatchesRegularExpression('/Hakkımızda.*Randevu.*Bize yazın.*Kampanya/s', $nav);
        $this->assertStringContainsString('href="https://calendly.com/acme" target="_blank" rel="noopener"', $nav);
        $this->assertStringContainsString('href="/kampanya"', $nav);

        // Geçersiz: javascript:, biçim hatası, 9 satır.
        foreach (['Kötü | javascript:alert(1)', 'yalnız etiket', implode("\n", array_fill(0, 9, 'A | https://a.example'))] as $bad) {
            $this->actingAs($owner)->withContext($acme)->from($menu)->put($url, ['links' => $bad])->assertRedirect($menu)->assertSessionHasErrors('links');
        }
        $this->assertCount(3, $site->fresh()->nav_links);

        // Boş gönderim temizler.
        $this->actingAs($owner)->withContext($acme)->put($url, ['links' => ''])->assertRedirect($menu);
        $this->assertNull($site->fresh()->nav_links);
        $this->assertStringNotContainsString('Randevu', $this->nav($this->get('http://acme.example/')->getContent()));
    }

    #[Test]
    public function musteri_site_ayarlarini_duzenler_ve_tenant_footerda_gorunur(): void
    {
        $acme = $this->organization('Acme');
        $acmeCo = $this->company($acme, 'Acme A.Ş.');
        $site = Website::create(['organization_id' => $acme->id, 'name' => 'Acme', 'slug' => 'acme', 'domain' => 'acme.example']);
        $owner = $this->owner($acme, $acmeCo);
        $default = Website::query()->default()->firstOrFail();

        // Müşteri sitesinde Ofisvio iletişim bilgisi SIZMAZ.
        $html = $this->get('http://acme.example/')->assertOk()->getContent();
        $this->assertStringNotContainsString(config('ofisvio.brand.phone'), $html);
        $this->assertStringNotContainsString(config('ofisvio.brand.email'), $html);

        $menu = "/panel/sirketler/{$acmeCo->id}/site/menu/{$site->id}";
        $this->actingAs($owner)->withContext($acme)->get($menu)->assertOk()->assertSee('Site genel ayarları');
        $this->actingAs($owner)->withContext($acme)->put("/panel/sirketler/{$acmeCo->id}/site/ayarlar/{$site->id}", ['contact_phone' => '+90 216 000 00 00', 'contact_email' => 'info@acme.example', 'tagline' => 'Acme slogan', 'address' => 'Kadıköy'])
            ->assertRedirect($menu)->assertSessionHasNoErrors();

        $html = $this->get('http://acme.example/')->assertOk()->getContent();
        $this->assertStringContainsString('href="tel:+902160000000"', $html);
        $this->assertStringContainsString('info@acme.example', $html);
        $this->assertStringContainsString('Acme slogan', $html);
        $this->assertStringContainsString('Kadıköy', $html);

        // Yabancı site 404; Ofisvio vitrini değişmez.
        $this->actingAs($owner)->withContext($acme)->put("/panel/sirketler/{$acmeCo->id}/site/ayarlar/{$default->id}", ['contact_phone' => '1'])->assertNotFound();
        $this->assertNull($default->fresh()->contact_phone);
    }

    private function nav(string $html): string
    {
        preg_match('/<nav class="nav-main".*?<\/nav>/s', $html, $m);

        return $m[0] ?? '';
    }
}
