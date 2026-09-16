<?php

namespace Tests\Feature\Panel;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Website;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 10/15 — Müşteri SEO alanı: seo.view (ayarlar+denetim), seo.edit (metin
 * alanları), seo.publish (indeksleme anahtarı, yalnız owner). Yabancı site 404;
 * Ofisvio vitrini müşteri rotasından değiştirilemez.
 */
class TenantSeoTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(WebsiteSeeder::class);
    }

    #[Test]
    public function sahip_seo_ayarlarini_duzenler_indekslemeyi_kapatir_ve_yabanci_siteye_erisemez(): void
    {
        $acme = $this->organization('Acme');
        $acmeCo = $this->company($acme, 'Acme A.Ş.');
        $site = Website::create(['organization_id' => $acme->id, 'name' => 'Acme', 'slug' => 'acme', 'domain' => 'acme.example']);
        $owner = $this->owner($acme, $acmeCo);
        $default = Website::query()->default()->firstOrFail();

        $page = Content::create(['website_id' => $site->id, 'kind' => 'page', 'slug' => 'hakkimizda', 'title' => 'Hakkımızda', 'body' => 'Kısa.']);
        $page->forceFill(['status' => ContentStatus::PUBLISHED, 'published_at' => now()->subDay()])->save();

        $base = "/panel/sirketler/{$acmeCo->id}/site/seo";

        // Görünüm: ayarlar, denetim bulgusu (kısa gövde), sitemap.
        $this->actingAs($owner)->withContext($acme)->get("/panel/sirketler/{$acmeCo->id}/site")->assertOk()->assertSee($base, false);
        $this->actingAs($owner)->withContext($acme)->get($base)->assertOk()
            ->assertSee('acme.example')->assertSee('Gövde 300 karakterden kısa.')->assertSee('https://acme.example/hakkimizda')
            ->assertSee('İndekslemeye kapat');

        // Metin alanları (seo.edit).
        $this->actingAs($owner)->withContext($acme)->put("$base/{$site->id}", [
            'seo_title_suffix' => '| Acme', 'seo_default_description' => 'Acme varsayılan açıklama.', 'seo_locale' => 'tr_TR',
        ])->assertRedirect($base)->assertSessionHasNoErrors();
        $this->assertSame('| Acme', $site->fresh()->seo_title_suffix);
        $this->assertTrue($site->fresh()->robots_index, 'Metin formu indeksleme bayrağına dokunmaz.');
        $this->get('http://acme.example/hakkimizda')->assertOk()->assertSee('<title>Hakkımızda | Acme</title>', false);

        // Geçersiz dil reddedilir.
        $this->actingAs($owner)->withContext($acme)->from($base)->put("$base/{$site->id}", ['seo_locale' => 'turkce'])->assertSessionHasErrors('seo_locale');

        // İndeksleme anahtarı (seo.publish): robots.txt Disallow, sitemap boş, noindex.
        $this->actingAs($owner)->withContext($acme)->put("$base/{$site->id}/indeksleme", ['robots_index' => 0])->assertRedirect($base);
        $this->assertFalse($site->fresh()->robots_index);
        $this->get('http://acme.example/robots.txt')->assertOk()->assertSee('Disallow: /');
        $this->get('http://acme.example/hakkimizda')->assertOk()->assertSee('<meta name="robots" content="noindex, nofollow">', false);
        $this->actingAs($owner)->withContext($acme)->get($base)->assertOk()->assertSee('İndekslemeye aç');
        $this->actingAs($owner)->withContext($acme)->put("$base/{$site->id}/indeksleme", ['robots_index' => 1])->assertRedirect($base);
        $this->assertTrue($site->fresh()->robots_index);

        // Yabancı site (Ofisvio vitrini): 404, değişmez.
        $this->actingAs($owner)->withContext($acme)->put("$base/{$default->id}", ['seo_title_suffix' => 'Hack', 'seo_locale' => 'tr_TR'])->assertNotFound();
        $this->actingAs($owner)->withContext($acme)->put("$base/{$default->id}/indeksleme", ['robots_index' => 0])->assertNotFound();
        $this->assertTrue($default->fresh()->robots_index);
        $this->assertNotSame('Hack', $default->fresh()->seo_title_suffix);
        $this->actingAs($owner)->withContext($acme)->get($base)->assertOk()->assertDontSee($default->name.' ·');
    }

    #[Test]
    public function company_admin_duzenler_ama_indekslemeyi_degistiremez_uye_giremez(): void
    {
        $acme = $this->organization('Acme');
        $acmeCo = $this->company($acme, 'Acme A.Ş.');
        $site = Website::create(['organization_id' => $acme->id, 'name' => 'Acme', 'slug' => 'acme', 'domain' => 'acme.example']);
        $base = "/panel/sirketler/{$acmeCo->id}/site/seo";

        $admin = $this->member($acme);
        $this->grantRole($admin, 'company_admin', ['company_id' => $acmeCo->id]);

        $this->actingAs($admin)->withContext($acme)->get($base)->assertOk()->assertSee('Kaydet')->assertDontSee('İndekslemeye kapat');
        $this->actingAs($admin)->withContext($acme)->put("$base/{$site->id}", ['seo_title_suffix' => '— Acme', 'seo_locale' => 'tr_TR'])->assertRedirect($base);
        $this->actingAs($admin)->withContext($acme)->put("$base/{$site->id}/indeksleme", ['robots_index' => 0])->assertForbidden();
        $this->assertTrue($site->fresh()->robots_index);

        $plain = $this->member($acme);
        $this->actingAs($plain)->withContext($acme)->get($base)->assertForbidden();

        // Personel SEO paneli müşteriye kapalı kalır.
        $this->actingAs($admin)->withContext($acme)->get('/panel/seo')->assertForbidden();
    }
}
