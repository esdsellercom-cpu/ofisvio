<?php

namespace Tests\Feature\Panel;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\ContentUrlHistory;
use App\Models\EntityRelation;
use App\Models\Location;
use App\Models\SeoLandingPage;
use App\Models\Service;
use App\Models\Website;
use App\Seo\HealthCenter;
use App\Services\ContentCache;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 60b — GEO Answer Engine (hizmet başına yapılandırılmış cevaplar → sayfa bölümleri, FAQPage, llms.txt),
 * Entity / Knowledge Graph (Article → Service/Location ilişkileri vitrine bağlantı olarak yansır, Topic → Entity),
 * programatik hizmet × şehir sayfaları (tek tek, kalite kapısı: uzunluk + benzerlik; yayın/sitemap/şema/silme).
 */
class GeoAnswerEngineTest extends TestCase
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

    private function publishPost(string $slug, string $title): Content
    {
        $post = Content::create(['website_id' => $this->site->id, 'kind' => 'post', 'slug' => $slug, 'title' => $title, 'excerpt' => 'Elli karakterden uzun bir özet metni; meta açıklama olarak yeterli uzunlukta.', 'body' => str_repeat('Gövde paragrafı. ', 40)]);
        $post->forceFill(['status' => ContentStatus::PUBLISHED, 'published_at' => now()->subMinute()])->save();
        app(ContentCache::class)->invalidate($this->site);

        return $post;
    }

    #[Test]
    public function hizmet_cevaplari_formdan_kaydedilir_sayfada_bolum_sss_semasi_ve_llms_olarak_cikar(): void
    {
        $admin = $this->staff('system_admin');
        $service = Service::query()->where('slug', 'sanal-ofis')->firstOrFail();
        $other = Service::query()->where('slug', '!=', 'sanal-ofis')->firstOrFail();
        $location = Location::query()->where('is_published', true)->firstOrFail();

        $this->actingAs($admin)->get("/panel/hizmetler/{$service->slug}/duzenle")->assertOk()->assertSee('GEO cevap motoru');
        $this->actingAs($admin)->put("/panel/hizmetler/{$service->slug}", [
            'name' => $service->name, 'summary' => $service->summary, 'description' => $service->description, 'price_text' => $service->price_text, 'is_active' => 1, 'sort_order' => 1,
            'answers' => [
                'what' => 'Sanal ofis, şirketinizin yasal adresini bizim şubemizde göstermenizi sağlayan hizmettir.',
                'who' => 'Yeni kurulan şirketler ve şubesiz çalışan ekipler için.',
                'documents' => "İmza sirküleri\nVergi levhası\n\nKira sözleşmesi",
                'process' => "Başvuru\nSözleşme\nAdres tahsisi",
                'faq' => [['q' => 'Sanal ofis adresi tescile uygun mu?', 'a' => 'Evet, ticaret sicil ve vergi dairesi kaydında kullanılabilir.'], ['q' => 'Posta nasıl bildirilir?', 'a' => 'Gelen evrak aynı gün e-posta ile bildirilir.'], ['q' => 'Boş soru', 'a' => '']],
                'related_services' => [$other->id, $service->id, 999999],
                'related_locations' => [$location->id],
            ],
        ])->assertRedirect('/panel/hizmetler')->assertSessionHasNoErrors();

        $service->refresh();
        $this->assertSame(['İmza sirküleri', 'Vergi levhası', 'Kira sözleşmesi'], $service->answers['documents']);
        $this->assertCount(2, $service->faqPairs(), 'boş cevaplı satır atılır');
        $this->assertSame([$other->id], $service->answers['related_services'], 'kendisi ve var olmayan kimlik düşer');
        $this->assertSame([$location->id], $service->answers['related_locations']);

        $page = $this->get('http://localhost'.$service->path())->assertOk()->getContent();
        $this->assertStringContainsString('<h2 class="h3" style="margin:0 0 8px">Nedir?</h2>', $page);
        $this->assertStringContainsString('Gerekli belgeler', $page);
        $this->assertStringContainsString('<li>Vergi levhası</li>', $page);
        $this->assertStringContainsString('Sanal ofis adresi tescile uygun mu?', $page);
        $this->assertStringContainsString('"@type":"FAQPage"', $page);
        $this->assertStringContainsString('"name":"Posta nasıl bildirilir?"', $page);
        $this->assertStringContainsString('İlgili hizmetler', $page);
        $this->assertStringContainsString('href="'.$other->path().'"', $page);
        $this->assertStringContainsString('href="'.$location->path().'"', $page);

        $llms = $this->get('http://localhost/llms.txt')->assertOk()->getContent();
        $this->assertStringContainsString('- Nedir: Sanal ofis, şirketinizin yasal adresini', $llms);

        // GEO Manager + Command Center kapsamı.
        $this->actingAs($admin)->get('/panel/seo/geo-yonetimi')->assertRedirect("/panel/seo/{$this->site->id}/geo-yonetimi");
        $this->actingAs($admin)->get("/panel/seo/{$this->site->id}/geo-yonetimi")->assertOk()->assertSee('GEO Answer Engine')->assertSee('7/13');
        $report = app(HealthCenter::class)->report($this->site);
        $this->assertNull(collect($report['issues'])->first(fn (array $i) => str_contains($i['title'], 'Yapılandırılmış cevap yok: '.$service->name)));
        $this->assertNotNull(collect($report['issues'])->first(fn (array $i) => str_contains($i['title'], 'Yapılandırılmış cevap yok: '.$other->name)));
    }

    #[Test]
    public function knowledge_graph_iliskileri_vitrine_baglanti_olarak_yansir(): void
    {
        $admin = $this->staff('system_admin');
        $finance = $this->staff('finance_admin');
        $service = Service::query()->where('slug', 'sanal-ofis')->firstOrFail();
        $location = Location::query()->where('is_published', true)->firstOrFail();
        $post = $this->publishPost('sanal-ofis-rehberi', 'Sanal ofis rehberi');

        $this->actingAs($finance)->get('/panel/seo/varliklar')->assertForbidden();
        $this->actingAs($admin)->get('/panel/seo/varliklar')->assertRedirect("/panel/seo/{$this->site->id}/varliklar");
        $this->actingAs($admin)->get("/panel/seo/{$this->site->id}/varliklar?yazi={$post->id}")->assertOk()->assertSee('Entity / Knowledge Graph')->assertSee('Sanal ofis rehberi')->assertSee('ilişkisiz');

        // İlişkisiz yazı Command Center'da bulgu; bağlanınca düşer.
        $this->assertNotNull(collect(app(HealthCenter::class)->report($this->site)['issues'])->first(fn (array $i) => str_contains($i['title'], 'bağlı değil: Sanal ofis rehberi')));

        $this->actingAs($admin)->post("/panel/seo/{$this->site->id}/varliklar/iliski", ['content_id' => $post->id, 'services' => [$service->id, 999999], 'locations' => [$location->id]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, EntityRelation::query()->where('from_type', 'content')->where('from_id', (string) $post->id)->count());

        $servicePage = $this->get('http://localhost'.$service->path())->assertOk()->getContent();
        $this->assertStringContainsString('İlgili yazılar', $servicePage);
        $this->assertStringContainsString('href="'.$post->path().'"', $servicePage);
        $this->assertStringContainsString('href="'.$post->path().'"', $this->get('http://localhost'.$location->path())->assertOk()->getContent());
        $postPage = $this->get('http://localhost'.$post->path())->assertOk()->getContent();
        $this->assertStringContainsString('Bu yazının konusu olan hizmetler', $postPage);
        $this->assertStringContainsString('href="'.$service->path().'"', $postPage);
        $this->assertNull(collect(app(HealthCenter::class)->report($this->site)['issues'])->first(fn (array $i) => str_contains($i['title'], 'bağlı değil: Sanal ofis rehberi')));

        // Konu → varlık; olmayan hedef reddedilir; kaldırma.
        $this->actingAs($admin)->post("/panel/seo/{$this->site->id}/varliklar/konu", ['topic' => 'Şirket Kuruluşu', 'to_type' => 'service', 'to_id' => $service->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/panel/seo/{$this->site->id}/varliklar/konu", ['topic' => 'x', 'to_type' => 'service', 'to_id' => 999999])->assertSessionHasErrors('topic');
        $topic = EntityRelation::query()->where('from_type', 'topic')->firstOrFail();
        $this->assertSame('sirket-kurulusu', $topic->from_id);
        $this->actingAs($admin)->get("/panel/seo/{$this->site->id}/varliklar")->assertOk()->assertSee('sirket-kurulusu');
        $this->actingAs($admin)->delete("/panel/seo/{$this->site->id}/varliklar/{$topic->id}")->assertRedirect();
        $this->assertNull(EntityRelation::query()->find($topic->id));
    }

    #[Test]
    public function programatik_sayfa_tek_tek_olusur_kalite_kapisi_yayini_korur_ve_vitrine_tam_cikar(): void
    {
        $admin = $this->staff('system_admin');
        $ops = $this->staff('operations_admin'); // seo.edit + seo.publish
        $finance = $this->staff('finance_admin');
        $service = Service::query()->where('slug', 'sanal-ofis')->firstOrFail();
        $location = Location::query()->where('is_published', true)->firstOrFail();
        $citySlug = Str::slug($location->city);
        $base = "/panel/seo/{$this->site->id}/programatik";

        $this->actingAs($finance)->get('/panel/seo/programatik')->assertForbidden();
        $this->actingAs($admin)->get('/panel/seo/programatik')->assertRedirect($base);
        $this->actingAs($admin)->get($base)->assertOk()->assertSee('Programatik SEO')->assertDontSee('Toplu üret');
        $this->actingAs($admin)->get($base.'/yeni')->assertOk()->assertSee('{sehir_da}');

        // Kısa giriş → taslak oluşur ama kalite kapısı kapalı; yayın reddedilir; vitrin 404.
        $this->actingAs($ops)->post($base, ['service_id' => $service->id, 'location_id' => $location->id, 'title' => '{sehir_da} {hizmet}', 'meta_description' => 'Kısa açıklama.', 'intro' => 'Çok kısa bir giriş.'])->assertRedirect()->assertSessionHasNoErrors();
        $page = SeoLandingPage::query()->firstOrFail();
        $this->assertSame($location->city."'da ".$service->name, $page->title);
        $this->assertSame('/sanal-ofis/'.$citySlug, $page->path());
        $this->assertFalse($page->quality['ok']);
        $this->actingAs($ops)->from($base)->post("{$base}/{$page->id}/yayinla")->assertSessionHasErrors('intro');
        $this->assertSame('draft', $page->fresh()->status);
        $this->get('http://localhost'.$page->path())->assertNotFound();

        // Aynı hizmet × lokasyon ikinci kez: reddedilir (toplu/kopya sayfa yok).
        $this->actingAs($ops)->from($base.'/yeni')->post($base, ['service_id' => $service->id, 'location_id' => $location->id, 'intro' => str_repeat('metin ', 100)])->assertSessionHasErrors('intro');
        $this->assertSame(1, SeoLandingPage::count());

        // Hizmet açıklamasının kopyası → benzerlik kapısı.
        $copy = trim((string) $service->summary.' '.$service->description.' '.$service->description);
        $this->actingAs($ops)->put("{$base}/{$page->id}", ['title' => 'Kopya', 'meta_description' => 'x', 'intro' => str_pad($copy, 450, ' '.$copy)])->assertRedirect();
        $this->assertStringContainsString('benziyor', implode(' ', $page->fresh()->quality['issues']));

        // Şehre özgü benzersiz metin → kapı açılır → yayın → vitrin + sitemap + şema + hizmet sayfası bağlantısı.
        $unique = 'Konya Sanayi bölgesindeki KOBİ sahipleri, Selçuklu ve Meram ilçelerinden gelen serbest meslek erbabı ve şehir dışından Konya pazarına açılan firmalar için tescil adresi ihtiyacı burada karşılanır. Şube tramvay hattına iki dakika, otoparkı ücretsizdir; gelen evrak aynı gün taranıp iletilir, ziyaretçiler resepsiyonda karşılanır. Sözleşme aynı gün imzalanır ve adres ertesi iş günü ticaret sicilinde kullanılabilir. Muhasebecinizle koordinasyon için evrak teslim tutanağı hazırlanır ve aylık raporlama e-posta ile yapılır.';
        $this->actingAs($ops)->put("{$base}/{$page->id}", ['title' => '{sehir_da} {hizmet}', 'meta_description' => '{sehir_da} tescile uygun sanal ofis adresi: {sube}.', 'intro' => $unique, 'faq' => [['q' => 'Adres aynı gün hazır mı?', 'a' => 'Evet, sözleşme sonrası aynı gün.'], ['q' => 'Otopark var mı?', 'a' => 'Ücretsiz otopark vardır.']], 'is_indexable' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $page->refresh();
        $this->assertTrue($page->quality['ok'], implode(' | ', $page->quality['issues']));
        $this->assertStringContainsString($location->name, (string) $page->meta_description, 'şablon değişkeni DB değeriyle dolar');
        $this->actingAs($ops)->post("{$base}/{$page->id}/yayinla")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('published', $page->fresh()->status);

        $html = $this->get('http://localhost'.$page->path())->assertOk()->getContent();
        $this->assertStringContainsString('<title>'.e($page->title).' — Ofisvio</title>', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.$this->site->baseUrl().$page->path().'">', $html);
        $this->assertStringContainsString('Konya Sanayi bölgesindeki KOBİ sahipleri', $html);
        $this->assertStringContainsString('"@type":"Service"', $html);
        $this->assertStringContainsString('"@type":"LocalBusiness"', $html);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
        $this->assertStringContainsString('"name":"Otopark var mı?"', $html);
        $this->assertStringContainsString('href="'.$location->path().'"', $html);
        $this->assertStringContainsString($page->path(), $this->get('http://localhost/sitemap.xml')->assertOk()->getContent());
        $this->assertStringContainsString('Şehirlere göre', $this->get('http://localhost'.$service->path())->assertOk()->getContent());
        $this->assertStringContainsString('href="'.$page->path().'"', $this->get('http://localhost'.$location->path())->assertOk()->getContent());
        $this->actingAs($admin)->get("/panel/seo/{$this->site->id}/sema?yol=".$page->path())->assertOk()->assertSee('&quot;@type&quot;: &quot;LocalBusiness&quot;', false);

        // noindex seçimi → robots noindex, sitemap dışı.
        $this->actingAs($ops)->put("{$base}/{$page->id}", ['title' => $page->title, 'meta_description' => $page->meta_description, 'intro' => $unique, 'is_indexable' => 0])->assertRedirect();
        $this->assertStringContainsString('content="noindex, follow"', $this->get('http://localhost'.$page->path())->assertOk()->getContent());
        $this->assertStringNotContainsString($page->path(), $this->get('http://localhost/sitemap.xml')->getContent());

        // Yayından kaldır → 404; sil → URL geçmişi (faz 54 kancası).
        $this->actingAs($ops)->post("{$base}/{$page->id}/kaldir")->assertRedirect();
        $this->get('http://localhost'.$page->path())->assertNotFound();
        $this->actingAs($ops)->get("{$base}/{$page->id}/sil")->assertOk()->assertSee('Silme onayı');
        $this->actingAs($ops)->delete("{$base}/{$page->id}", ['redirect_mode' => 'custom', 'redirect_custom' => $service->path()])->assertRedirect($base);
        $this->assertSame(0, SeoLandingPage::count());
        $this->assertTrue(ContentUrlHistory::query()->where('old_path', $page->path())->exists());
        $this->assertSame(301, $this->get('http://localhost'.$page->path())->getStatusCode());
    }
}
