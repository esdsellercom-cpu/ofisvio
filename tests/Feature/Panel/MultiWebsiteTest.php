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
 * Faz 10 — çoklu website: Host -> Website çözümlemesi, sitelerin içerik
 * izolasyonu, müşteri sitesi iskeleti, website yönetimi izinleri.
 */
class MultiWebsiteTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    private Website $default;

    private Website $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(WebsiteSeeder::class);
        $this->default = Website::query()->default()->firstOrFail();

        $acme = $this->organization('Acme');
        $this->tenant = Website::create([
            'organization_id' => $acme->id,
            'name' => 'Acme Holding',
            'slug' => 'acme',
            'domain' => 'acme.example',
        ]);
    }

    private function live(Website $website, string $kind, string $slug, string $title): Content
    {
        // status $fillable dışında (tek yazar kuralı: ContentService); test forceFill ile yayınlar.
        $content = Content::create(['website_id' => $website->id, 'kind' => $kind, 'slug' => $slug, 'title' => $title, 'body' => 'Gövde '.$title]);
        $content->forceFill(['status' => ContentStatus::PUBLISHED, 'published_at' => now()->subMinute()])->save();

        return $content;
    }

    #[Test]
    public function host_eslesirse_musteri_sitesi_aksi_halde_ofisvio_gosterilir(): void
    {
        $this->live($this->tenant, 'page', 'hakkimizda', 'Acme Hakkında');
        $this->live($this->default, 'page', 'hakkimizda', 'Ofisvio Hakkında');

        // Ofisvio vitrini (bilinmeyen/varsayılan host): pazarlama sayfası.
        $this->get('/')->assertOk()->assertSee('Şirketinizin adresi')->assertDontSee('Acme Holding');
        $this->get('/hakkimizda')->assertOk()->assertSee('Ofisvio Hakkında')->assertDontSee('Acme Hakkında');

        // Müşteri alan adı (port ve büyük harf yok sayılır): kendi sitesi.
        $this->get('http://ACME.example:8443/')->assertOk()->assertSee('Acme Holding')->assertDontSee('Şirketinizin adresi');
        $this->get('http://acme.example/hakkimizda')->assertOk()->assertSee('Acme Hakkında')->assertDontSee('Ofisvio Hakkında');

        // Karşı yönde sızıntı yok: diğer sitenin slug'ı 404.
        $this->live($this->default, 'post', 'yalnizca-ofisvio', 'Yalnızca Ofisvio');
        $this->get('http://acme.example/blog/yalnizca-ofisvio')->assertNotFound();
        $this->get('http://acme.example/blog')->assertOk()->assertDontSee('Yalnızca Ofisvio');
    }

    #[Test]
    public function bilinmeyen_host_yeni_site_uretmez_varsayilana_duser(): void
    {
        $this->get('http://bilinmeyen.example/')->assertOk()->assertSee('Şirketinizin adresi');
        $this->assertSame(2, Website::count());
    }

    #[Test]
    public function personel_website_acar_ve_icerigini_ayri_yonetir(): void
    {
        $admin = $this->staff('system_admin');
        $beta = $this->organization('Beta');

        $this->actingAs($admin)->get('/panel/websiteler')->assertOk()->assertSee('Acme Holding')->assertSee('Varsayılan');

        $this->actingAs($admin)->post('/panel/websiteler', [
            'name' => 'Beta Sitesi', 'domain' => 'Beta.Example', 'organization_id' => $beta->id,
        ])->assertRedirect('/panel/websiteler');

        $site = Website::where('slug', 'beta-sitesi')->firstOrFail();
        $this->assertSame('beta.example', $site->domain); // küçük harfe indirildi
        $this->assertSame($beta->id, (int) $site->organization_id);

        // Aynı alan adı ikinci kez atanamaz; geçersiz alan adı reddedilir.
        $this->actingAs($admin)->from('/panel/websiteler/yeni')
            ->post('/panel/websiteler', ['name' => 'Kopya', 'domain' => 'beta.example'])
            ->assertSessionHasErrors('domain');
        $this->actingAs($admin)->from('/panel/websiteler/yeni')
            ->post('/panel/websiteler', ['name' => 'Bozuk', 'domain' => 'http://x.com/yol'])
            ->assertSessionHasErrors('domain');

        // Varsayılan site organizasyona bağlanamaz.
        $this->actingAs($admin)->from("/panel/websiteler/{$this->default->id}/duzenle")
            ->put("/panel/websiteler/{$this->default->id}", ['name' => 'Ofisvio', 'organization_id' => $beta->id])
            ->assertSessionHasErrors('organization_id');

        // İçerik editörü site seçer; içerik seçilen siteye yazılır.
        $this->actingAs($admin)->get('/panel/icerik?website='.$site->id)->assertOk()->assertSee('Beta Sitesi');
        $this->actingAs($admin)->post('/panel/icerik', [
            'website_id' => $site->id, 'kind' => 'page', 'title' => 'İletişim', 'body' => 'Beta iletişim',
        ])->assertRedirect();
        $page = Content::where('slug', 'iletisim')->firstOrFail();
        $this->assertSame($site->id, (int) $page->website_id);

        // Listede yalnızca seçili sitenin içeriği.
        $this->actingAs($admin)->get('/panel/icerik?website='.$site->id)->assertSee('İletişim')->assertDontSee('Aydınlatma Metni');
        $this->actingAs($admin)->get('/panel/icerik')->assertSee('Aydınlatma Metni')->assertDontSee('İletişim');

        // Yayınlanınca yalnızca kendi alan adında.
        $this->actingAs($admin)->post("/panel/icerik/{$page->id}/incelemeye-gonder");
        $this->actingAs($admin)->post("/panel/icerik/{$page->id}/yayinla");
        $this->get('http://beta.example/iletisim')->assertOk()->assertSee('Beta iletişim');
        // Not: test istemcisi son isteğin Host'unu taşır; Ofisvio vitrini için host açıkça verilir.
        $this->get('http://localhost/iletisim')->assertNotFound();
    }

    #[Test]
    public function website_yonetimi_izin_ister(): void
    {
        $ops = $this->staff('operations_admin'); // website.view var, manage yok
        $this->actingAs($ops)->get('/panel/websiteler')->assertOk()->assertDontSee('Yeni site');
        $this->actingAs($ops)->post('/panel/websiteler', ['name' => 'Kaçak'])->assertForbidden();

        $finance = $this->staff('finance_admin'); // hiçbiri yok
        $this->actingAs($finance)->get('/panel/websiteler')->assertForbidden();

        $acme = $this->organization('Acme2');
        $owner = $this->owner($acme);
        $this->actingAs($owner)->get('/panel/websiteler')->assertForbidden();
    }

    #[Test]
    public function site_silme_yalniz_icerigi_olmayan_varsayilan_disi_site(): void
    {
        $admin = $this->staff('system_admin');
        $default = Website::query()->default()->firstOrFail();

        // Varsayılan silinemez; içerikli tenant site silinemez.
        $this->live($this->tenant, 'page', 'hakkimizda', 'Acme Hakkında');
        $this->actingAs($admin)->from("/panel/websiteler/{$default->id}/duzenle")->delete("/panel/websiteler/{$default->id}")->assertSessionHasErrors('name');
        $this->actingAs($admin)->from("/panel/websiteler/{$this->tenant->id}/duzenle")->delete("/panel/websiteler/{$this->tenant->id}")->assertSessionHasErrors('name');
        $this->assertNotNull(Website::find($this->tenant->id));

        // İçeriksiz site silinir (soft); alan adı yeniden kullanılabilir; vitrin varsayılana düşer.
        $empty = Website::create(['organization_id' => $this->tenant->organization_id, 'name' => 'Boş', 'slug' => 'bos', 'domain' => 'bos.example']);
        $this->actingAs($admin)->delete("/panel/websiteler/{$empty->id}")->assertRedirect('/panel/websiteler');
        $this->assertNull(Website::find($empty->id));
        $this->assertNotNull(Website::withTrashed()->find($empty->id));
        $this->get('http://bos.example/')->assertOk()->assertDontSee('Boş');
        $this->actingAs($admin)->post('/panel/websiteler', ['name' => 'Yeni Boş', 'domain' => 'bos.example'])->assertRedirect()->assertSessionHasNoErrors();

        // website.manage olmayan personel silemez.
        $this->actingAs($this->staff('operations_admin'))->delete("/panel/websiteler/{$this->tenant->id}")->assertForbidden();
    }
}
