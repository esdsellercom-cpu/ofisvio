<?php

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\SiteRevision;
use App\Models\SiteSection;
use App\Models\Website;
use App\Services\SiteBuilderService;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sayfa kurucu (§21–36): taslak ≠ yayın, bölüm ekle/taşı/gizle/çoğalt/sil/zamanla/cihaz,
 * CTA eylem tipleri, yayın → revizyon + önbellek, geri alma, imzalı önizleme (taslak
 * sızmaz), üst menü yayınlanmış bölümlerden, yetki (edit vs publish), denetim izi.
 */
class SiteBuilderTest extends TestCase
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
    public function taslak_yayindan_ayridir_onizleme_imzalidir_ve_menu_bolumlerden_gelir(): void
    {
        $admin = $this->staff('system_admin');   // content.edit + content.publish
        $ops = $this->staff('operations_admin'); // content.edit, publish yok
        $finance = $this->staff('finance_admin');

        // Yayın yokken vitrin varsayılan yerleşimi basar; menü çapaları varsayılan bölümlerden.
        $home = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('id="cozumler"', $home);
        $this->assertStringContainsString('href="#cozumler">Çözümler<', $home);
        $this->assertStringContainsString('id="teklif"', $home);

        $this->actingAs($finance)->get('/panel/icerik/tasarim')->assertForbidden();
        $this->actingAs($ops)->get('/panel/icerik/tasarim')->assertOk()->assertSee('Ana sayfa tasarımı')->assertSee('Çözümler')->assertDontSee('Yayınla</button>', false);
        $this->assertSame(11, SiteSection::count()); // ilk açılış: varsayılan yerleşim taslağa yazıldı (faz 53: + franchise)

        // Serbest metin + CTA şeridi ekle (content.edit), çözümleri gizle, SSS ekle; taslak vitrine ÇIKMAZ.
        $this->actingAs($ops)->post("/panel/icerik/tasarim/{$this->site->id}/bolum", ['type' => 'rich_text'])->assertRedirect()->assertSessionHasNoErrors();
        $rich = SiteSection::query()->where('type', 'rich_text')->firstOrFail();
        $this->actingAs($ops)->put("/panel/icerik/tasarim/{$this->site->id}/bolum/{$rich->id}", ['settings' => ['title' => 'Neden Ofisvio', 'body' => "**Kalıcı** adres.\n\n[site](https://ornek.test)", 'cta' => ['action' => 'booking', 'label' => 'Oda ayırt']], 'anchor' => 'neden', 'is_visible' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($ops)->post("/panel/icerik/tasarim/{$this->site->id}/bolum", ['type' => 'cta_banner'])->assertRedirect();
        $banner = SiteSection::query()->where('type', 'cta_banner')->firstOrFail();
        // CTA doğrulama: https dışı dış bağlantı reddedilir; WhatsApp eylemi site ayarı (numara) yoksa düğme basılmaz.
        $this->actingAs($ops)->from('/panel/icerik/tasarim')->put("/panel/icerik/tasarim/{$this->site->id}/bolum/{$banner->id}", ['settings' => ['title' => 'Hemen arayın', 'cta' => ['action' => 'url', 'target' => 'http://ornek.test', 'label' => 'Git']], 'is_visible' => 1])->assertSessionHasErrors('builder');
        $this->actingAs($ops)->put("/panel/icerik/tasarim/{$this->site->id}/bolum/{$banner->id}", ['settings' => ['title' => 'Hemen arayın', 'cta' => ['action' => 'whatsapp', 'label' => 'WhatsApp yaz'], 'style' => 'light'], 'is_visible' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $solutions = SiteSection::query()->where('type', 'solutions')->firstOrFail();
        $this->actingAs($ops)->post("/panel/icerik/tasarim/{$this->site->id}/bolum/{$solutions->id}/gorunurluk")->assertRedirect();
        $this->actingAs($ops)->post("/panel/icerik/tasarim/{$this->site->id}/bolum", ['type' => 'faq', 'after' => $rich->id])->assertRedirect();
        $faq = SiteSection::query()->where('type', 'faq')->firstOrFail();
        $this->actingAs($ops)->put("/panel/icerik/tasarim/{$this->site->id}/bolum/{$faq->id}", ['settings' => ['title' => 'SSS', 'items' => "Sözleşme süresi? | 1 aydan başlar\nBozuk satır\nDepozito? | Bir aylık bedel"], 'is_visible' => 1, 'hide_on_mobile' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($rich->sort_order + 1, $faq->fresh()->sort_order);
        // Tekil bölüm ikinci kez eklenemez; çoğaltılamaz.
        $this->actingAs($ops)->from('/panel/icerik/tasarim')->post("/panel/icerik/tasarim/{$this->site->id}/bolum", ['type' => 'hero'])->assertSessionHasErrors('builder');
        $this->actingAs($ops)->from('/panel/icerik/tasarim')->post("/panel/icerik/tasarim/{$this->site->id}/bolum/{$solutions->id}/cogalt")->assertSessionHasErrors('builder');
        $this->actingAs($ops)->post("/panel/icerik/tasarim/{$this->site->id}/bolum/{$rich->id}/cogalt")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, SiteSection::query()->where('type', 'rich_text')->count());
        $this->assertSame('rich-text', SiteSection::query()->where('type', 'rich_text')->orderByDesc('id')->firstOrFail()->anchor); // ilki 'neden' oldu; kopya benzersiz çapa alır

        $live = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringNotContainsString('Neden Ofisvio', $live);
        $this->assertStringContainsString('id="cozumler"', $live);

        // Önizleme: imzasız 403; imzalı taslağı gösterir, noindex; yayınlanmamış içerik yalnız burada.
        $this->get('/onizleme/'.$this->site->id)->assertForbidden();
        $preview = $this->get(app(SiteBuilderService::class)->previewUrl($this->site))->assertOk()->getContent();
        $this->assertStringContainsString('Neden Ofisvio', $preview);
        $this->assertStringContainsString('<strong>Kalıcı</strong>', $preview);
        $this->assertStringContainsString('href="/rezervasyon"', $preview);
        $this->assertStringContainsString('noindex, nofollow', $preview);
        $this->assertStringNotContainsString('id="cozumler"', $preview);
        $this->assertStringContainsString('hide-mobile', $preview);
        $this->assertStringContainsString('FAQPage', $preview);
        $this->assertStringNotContainsString('Bozuk satır', $preview);
        $this->assertStringContainsString('Hemen arayın', $preview);
        $this->assertStringNotContainsString('>WhatsApp yaz<', $preview); // WhatsApp numarası ayarı yok → düğme yok
        Carbon::setTestNow(Carbon::now()->addMinutes(SiteBuilderService::PREVIEW_MINUTES + 1));
        $this->get(str_replace('http://localhost', '', (string) parse_url(app(SiteBuilderService::class)->previewUrl($this->site), PHP_URL_PATH)))->assertForbidden();
        Carbon::setTestNow();

        // Yayın: operations_admin yapamaz; admin yapar → revizyon 1, vitrin güncel, menüde çözüm çapası yok.
        $this->actingAs($ops)->post("/panel/icerik/tasarim/{$this->site->id}/yayinla")->assertForbidden();
        $this->actingAs($admin)->get('/panel/icerik/tasarim')->assertOk()->assertSee('yayınlanmamış değişiklik');
        $this->actingAs($admin)->post("/panel/icerik/tasarim/{$this->site->id}/yayinla", ['note' => 'İlk yayın'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, SiteRevision::count());
        $live = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('Neden Ofisvio', $live);
        $this->assertStringNotContainsString('id="cozumler"', $live);
        $this->assertStringNotContainsString('href="#cozumler">Çözümler<', $live); // menüde yok (footer maddeleri ayrı veri)
        $this->assertStringContainsString('href="#nasil">Nasıl çalışır<', $live);
        $this->assertStringContainsString('id="neden"', $live);
        $this->actingAs($admin)->get('/panel/icerik/tasarim')->assertOk()->assertSee('yayınla eşit');

        // Zamanlama: gelecekte başlayan bölüm yayında görünmez; süresi gelince görünür (önbellek sürümü aynı → renderable süzer).
        $this->actingAs($ops)->put("/panel/icerik/tasarim/{$this->site->id}/bolum/{$banner->id}", ['settings' => ['title' => 'Kampanya', 'style' => 'dark'], 'is_visible' => 1, 'publish_from' => Carbon::now()->addDay()->format('Y-m-d\TH:i')])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/panel/icerik/tasarim/{$this->site->id}/yayinla")->assertRedirect();
        $this->assertStringNotContainsString('Kampanya', $this->get('http://localhost/')->getContent());
        Carbon::setTestNow(Carbon::now()->addDays(2));
        $this->assertStringContainsString('Kampanya', $this->get('http://localhost/')->getContent());
        Carbon::setTestNow();

        // Geri alma: revizyon 1'e dön → yeni revizyon 3; "Kampanya" yok, çözümler gizli kalır.
        $first = SiteRevision::query()->where('number', 1)->firstOrFail();
        $this->actingAs($ops)->post("/panel/icerik/tasarim/{$this->site->id}/geri-al/{$first->id}")->assertForbidden();
        $this->actingAs($admin)->post("/panel/icerik/tasarim/{$this->site->id}/geri-al/{$first->id}")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(3, SiteRevision::count());
        Carbon::setTestNow(Carbon::now()->addDays(2));
        $this->assertStringNotContainsString('Kampanya', $this->get('http://localhost/')->getContent());
        Carbon::setTestNow();
        $this->assertSame('Geri alma: revizyon 1', SiteRevision::query()->orderByDesc('number')->firstOrFail()->note);

        // Geri alma taslağı yeniden yazdı: bölüm id'leri değişti.
        $rich = SiteSection::query()->where('type', 'rich_text')->orderBy('id')->firstOrFail();
        $faq = SiteSection::query()->where('type', 'faq')->firstOrFail();

        // Sürükle-bırak sırası ve silme; başka siteye ait bölüm id 404/hata.
        $lastType = SiteSection::query()->orderByDesc('sort_order')->firstOrFail()->type;
        $ids = SiteSection::query()->orderBy('sort_order')->pluck('id')->reverse()->implode(',');
        $this->actingAs($ops)->post("/panel/icerik/tasarim/{$this->site->id}/sirala", ['order' => $ids])->assertRedirect();
        $this->assertSame($lastType, SiteSection::query()->orderBy('sort_order')->firstOrFail()->type);
        $this->assertSame('hero', SiteSection::query()->orderByDesc('sort_order')->firstOrFail()->type);
        $this->actingAs($ops)->delete("/panel/icerik/tasarim/{$this->site->id}/bolum/{$faq->id}")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($faq->fresh());
        $other = Website::create(['name' => 'Acme', 'slug' => 'acme', 'domain' => 'acme.example', 'organization_id' => $this->organization('Acme')->id]);
        $this->actingAs($ops)->from('/panel/icerik/tasarim')->delete("/panel/icerik/tasarim/{$other->id}/bolum/{$rich->id}")->assertSessionHasErrors('builder');
        $this->assertNotNull($rich->fresh());

        // Denetim izi.
        foreach (['site.section_added', 'site.section_updated', 'site.section_toggled', 'site.section_duplicated', 'site.published', 'site.rolled_back', 'site.sections_reordered', 'site.section_deleted'] as $action) {
            $this->assertTrue(AuditLog::query()->where('action', $action)->exists(), $action);
        }
        $this->actingAs($admin)->get('/panel/denetim?tur=general')->assertOk()->assertSee('site.published');
    }
}
