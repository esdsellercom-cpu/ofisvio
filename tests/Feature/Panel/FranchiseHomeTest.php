<?php

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\FranchiseApplication;
use App\Models\Location;
use App\Models\NotificationLog;
use App\Models\NotificationRecipient;
use App\Models\Service;
use App\Services\NotificationService;
use App\Site\Illustrations;
use App\Support\TurkishSuffix;
use Database\Seeders\LocationSeeder;
use Database\Seeders\ServiceSeeder;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 53: tek lokasyon (Konya) modu, boş görsel alanlarının illüstrasyonla dolması, ana sayfa franchise bölümü,
 * franchise başvuru zinciri (vitrin formu → doğrulama → DB → bildirim → panel listesi/detayı) ve franchise sayfası SEO'su.
 */
class FranchiseHomeTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(WebsiteSeeder::class);
        $this->seed(SiteBlockSeeder::class);
        $this->seed(ServiceSeeder::class);
    }

    private function konya(): Location
    {
        $konya = Location::create([
            'name' => 'Konya Merkez', 'slug' => 'konya-merkez', 'city' => 'Konya', 'region' => 'Konya', 'district' => 'Selçuklu', 'postal_code' => '42060',
            'address_line' => 'Test Cd. No: 1', 'badge' => 'merkez', 'price_from' => '', 'phone' => '0 332 000 00 00', 'opening_hours' => ['Hafta içi 08:30–18:30', 'Cumartesi 09:00–14:00'],
            'is_active' => true, 'is_published' => true, 'sort_order' => 1,
        ]);
        $konya->services()->sync(Service::query()->pluck('id')->mapWithKeys(fn ($id, $i) => [$id => ['sort_order' => $i + 1]])->all());
        // Yayında olmayan ikinci şube tek lokasyon modunu bozmaz.
        Location::create(['name' => 'Taslak Şube', 'slug' => 'taslak-sube', 'city' => 'Ankara', 'region' => 'Ankara', 'is_active' => true, 'is_published' => false]);

        return $konya;
    }

    #[Test]
    public function turkce_bulunma_eki_ve_illustrasyon_alt_metni(): void
    {
        $this->assertSame("Konya'da", TurkishSuffix::locative('Konya'));
        $this->assertSame("İzmir'de", TurkishSuffix::locative('İzmir'));
        $this->assertSame("Bursa'da", TurkishSuffix::locative('Bursa'));
        $this->assertSame("Muş'ta", TurkishSuffix::locative('Muş'));
        $this->assertSame("Zonguldak'ta", TurkishSuffix::locative('Zonguldak'));
        $this->assertSame("İstanbul'da", TurkishSuffix::locative('İstanbul'));
        $this->assertSame("Ataşehir'de", TurkishSuffix::locative('Ataşehir'));
        $this->assertSame('', TurkishSuffix::locative(' '));

        $this->assertSame('sanal-ofis', Illustrations::forService('sanal-ofis', 'Sanal Ofis'));
        $this->assertSame('toplanti-odasi', Illustrations::forService('toplanti-odasi', 'Toplantı Odası'));
        $this->assertSame('etkinlik-alani', Illustrations::forService('etkinlik-alani', 'Etkinlik Alanı'));
        $this->assertSame('coworking', Illustrations::forService('coworking', 'Coworking'));
        $this->assertSame('hazir-ofis', Illustrations::forService('bilinmeyen', 'Başka bir şey'));
        $this->assertSame("Konya'da sanal ofis: yasal adres, posta ve evrak yönetimi — illüstrasyon", Illustrations::alt('sanal-ofis', 'Konya'));
        $this->assertStringContainsString('images/illustrations/hero-office.svg', Illustrations::url('hero'));

        foreach (Illustrations::SET as [$file]) {
            $this->assertFileExists(public_path("images/illustrations/{$file}.svg"));
            $this->assertStringContainsString('<title', (string) file_get_contents(public_path("images/illustrations/{$file}.svg")));
        }
    }

    #[Test]
    public function tek_lokasyon_modunda_konya_one_cikar_bolge_secimi_kalkar_ve_bos_gorseller_dolar(): void
    {
        $konya = $this->konya();

        $html = $this->get('http://localhost/')->assertOk()->getContent();

        // Bölge seçimi / "lokasyonlarımız" listesi yok; şube gerçek verisiyle öne çıkar (adres, telefon, saatler, hizmetler).
        $this->assertStringNotContainsString('Tüm bölgeler', $html);
        $this->assertStringNotContainsString('Bölge filtresi', $html);
        $this->assertStringNotContainsString('data-locations>', $html);
        $this->assertStringContainsString('data-single-location-spotlight', $html);
        $this->assertStringContainsString('Konya Merkez', $html);
        $this->assertStringContainsString('Test Cd. No: 1', $html);
        $this->assertStringContainsString('Selçuklu, Konya 42060', $html);
        $this->assertStringContainsString('tel:0332000000', $html);
        $this->assertStringContainsString('Hafta içi 08:30–18:30', $html);
        $this->assertStringContainsString('/lokasyon/konya-merkez', $html);
        $this->assertStringNotContainsString('Yol tarifi', $html); // koordinat yok → uydurma harita bağlantısı yok

        // Şehre özel metinler ve SEO başlığı (yalnız DB'deki şehir adı).
        // Blade kesme işaretini &#039; olarak basar.
        $this->assertStringContainsString('Konya&#039;da sanal ofis · hazır ofis · coworking', $html);
        $this->assertStringContainsString('<title>Konya&#039;da sanal ofis, hazır ofis ve coworking', $html);
        $this->assertStringContainsString('Konya&#039;da işin merkezinde', $html);
        $this->assertStringContainsString('Konya · Selçuklu', $html);
        $this->assertStringNotContainsString('lokasyon · tüm bölgeler', $html);

        // İstatistikler lokasyon/şehir/bölge sayımı değil, şubenin verisi.
        $this->assertStringNotContainsString('>Şehir</div>', $html);
        $this->assertStringNotContainsString('>Bölge</div>', $html);
        $this->assertStringContainsString('>Çözüm</div>', $html);

        // Boş görsel alanları: hero + hizmet kartları + şube görseli illüstrasyonla, her biri alt metinli; "shot__note" yer tutucu yok.
        $this->assertStringContainsString('images/illustrations/hero-office.svg', $html);
        $this->assertStringContainsString('alt="Konya&#039;da modern ofis binası ve hazır çalışma masası — illüstrasyon"', $html);
        $this->assertStringContainsString('images/illustrations/sanal-ofis.svg', $html);
        $this->assertStringContainsString('images/illustrations/location.svg', $html);
        $this->assertStringNotContainsString('lokasyon ana görseli · 1200×1500', $html);
        $this->assertStringNotContainsString('kapak · 800×500', $html);
        $this->assertMatchesRegularExpression('~<img[^>]+illustrations/[a-z-]+\.svg"[^>]+alt="[^"]+"~', $html);

        // Footer/header: "Bölgeler" yerine şube.
        $this->assertStringNotContainsString('>Bölgeler<', $html);
        $this->assertStringContainsString('>Lokasyon<', $html);

        // Franchise bölümü ana sayfada (varsayılan yerleşim), CTA franchise sayfasına.
        $this->assertStringContainsString('id="franchise"', $html);
        $this->assertStringContainsString('Markamızı birlikte büyütmek ister misiniz?', $html);
        $this->assertStringContainsString('href="/franchise" class="btn btn--brand btn--pill"', $html);
        $this->assertStringContainsString('images/illustrations/franchise.svg', $html);

        // Şube sayfası da çalışır.
        $this->get('http://localhost/lokasyon/konya-merkez')->assertOk()->assertSee('Konya Merkez');
        $this->assertNotNull($konya->fresh());
    }

    #[Test]
    public function coklu_lokasyonda_bolge_secimi_ve_kartlar_korunur(): void
    {
        $this->seed(LocationSeeder::class);

        $html = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('Tüm bölgeler', $html);
        $this->assertStringContainsString('data-locations>', $html);
        $this->assertStringNotContainsString('data-single-location-spotlight', $html);
        $this->assertStringContainsString('<title>Şirketinizin adresi bugün hazır olsun', $html);
        $this->assertStringContainsString('images/illustrations/location.svg', $html); // kapaksız şube kartı
        $this->assertStringContainsString('id="franchise"', $html);
    }

    #[Test]
    public function franchise_basvurusu_vitrinden_dbye_bildirime_ve_panele_ucundan_ucuna(): void
    {
        $this->konya();
        $ops = $this->staff('operations_admin'); // franchise.view + franchise.manage
        app(NotificationService::class)->seedDefaultRules();
        Cache::flush();
        NotificationRecipient::query()->create(['name' => 'Ops', 'channel' => 'in_app', 'group' => 'crm', 'user_id' => $ops->id, 'is_active' => true]);

        // Sayfa: H1, form alanları (ad/soyad/firma/telefon/e-posta/şehir/ilçe/bütçe/deneyim/mesaj/KVKK), SEO/JSON-LD.
        $page = $this->get('http://localhost/franchise')->assertOk()->getContent();
        $this->assertStringContainsString('<h1 class="h1"', $page);
        $this->assertStringContainsString('Markamızı birlikte büyütmek ister misiniz?', $page);
        $this->assertStringContainsString('<title>Franchise ve iş ortaklığı başvurusu', $page);
        $this->assertMatchesRegularExpression('~rel="canonical" href="https?://[^"]+/franchise"~', $page);
        foreach (['first_name', 'last_name', 'company', 'phone', 'email', 'city', 'district', 'budget', 'experience', 'message', 'kvkk', 'website'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $page);
        }
        $this->assertStringContainsString('"@type":"FAQPage"', $page);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $page);
        $this->assertStringContainsString('"@type":"WebPage"', $page);
        $this->assertStringContainsString('1 – 2 milyon ₺', $page);

        $form = ['first_name' => 'Ayşe', 'last_name' => 'Demir', 'company' => 'Demir Girişim', 'phone' => '0532 000 00 00', 'email' => 'Ayse@ornek.com', 'city' => 'Konya', 'district' => 'Meram', 'budget' => '1m_2m', 'experience' => '8 yıl işletme', 'message' => 'Meram bölgesi', 'kvkk' => '1'];

        // Doğrulama: ad/soyad, e-posta, geçersiz bütçe, bal küpü.
        $this->from('/franchise')->post('/franchise', ['first_name' => ''] + $form)->assertSessionHasErrors('first_name');
        $this->from('/franchise')->post('/franchise', ['email' => 'bozuk'] + $form)->assertSessionHasErrors('email');
        $this->from('/franchise')->post('/franchise', ['budget' => 'cok'] + $form)->assertSessionHasErrors('budget');
        $this->from('/franchise')->post('/franchise', ['website' => 'bot'] + $form)->assertSessionHasErrors('website');
        $this->assertSame(0, FranchiseApplication::count());

        // Başvuru → DB (numara, ad soyad, firma) → denetim → bildirim.
        $this->post('/franchise', $form)->assertRedirect('/franchise')->assertSessionHasNoErrors();
        $this->get('/franchise')->assertOk()->assertSee('Başvurunuz alındı');
        // Hız sınırı (5/dk, misafir = IP kovası): 4 doğrulama + 1 kayıt = 5 istek; altıncı kesilir.
        $this->post('/franchise', ['email' => 'ikinci@ornek.com'] + $form)->assertStatus(429);
        $this->assertSame(1, FranchiseApplication::count());
        $app = FranchiseApplication::query()->firstOrFail();
        $this->assertSame('FR-'.date('Y').'-000001', $app->number);
        $this->assertSame(['Ayşe Demir', 'Ayşe', 'Demir', 'Demir Girişim', 'ayse@ornek.com', 'new', '1m_2m'], [$app->name, $app->first_name, $app->last_name, $app->company, $app->email, $app->status, $app->budget]);
        $this->assertNotNull($app->consented_at);
        $this->assertTrue(AuditLog::query()->where('action', 'franchise.applied')->where('entity_id', $app->id)->exists());
        $log = NotificationLog::query()->where('event', 'franchise.applied')->where('channel', 'in_app')->firstOrFail();
        $this->assertStringContainsString($app->number, (string) $log->subject);

        // Panel: gelen kutusu, liste sütunları, detay, durum akışı (Yeni → Görüşme → Olumlu), eski durum adı reddedilir.
        $this->actingAs($ops)->get('/panel/bildirimler/gelen')->assertOk()->assertSee($app->number);
        $list = $this->actingAs($ops)->get('/panel/franchise')->assertOk()->getContent();
        foreach ([$app->number, 'Ayşe Demir', 'Demir Girişim', '0532 000 00 00', 'ayse@ornek.com', 'Konya / Meram', '1 – 2 milyon ₺', 'Yeni', 'Başvuru no', 'Firma'] as $needle) {
            $this->assertStringContainsString($needle, $list);
        }
        $this->actingAs($ops)->get("/panel/franchise/{$app->id}")->assertOk()->assertSee($app->number)->assertSee('Demir Girişim')->assertSee('8 yıl işletme')->assertSee('Görüşme')->assertSee('Arşiv');
        $this->actingAs($ops)->put("/panel/franchise/{$app->id}", ['status' => 'meeting', 'internal_note' => 'Görüşme planlandı.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('meeting', $app->fresh()->status);
        $this->actingAs($ops)->get('/panel/franchise?status=meeting')->assertOk()->assertSee('Ayşe Demir');
        $this->actingAs($ops)->get('/panel/franchise?status=positive')->assertOk()->assertDontSee('Ayşe Demir');
        $this->actingAs($ops)->from("/panel/franchise/{$app->id}")->put("/panel/franchise/{$app->id}", ['status' => 'approved'])->assertSessionHasErrors('status');

        // İkinci başvuru sıradaki numarayı alır (sınır kapalı).
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->post('/franchise', ['email' => 'ikinci@ornek.com'] + $form)->assertSessionHasNoErrors();
        $this->assertSame('FR-'.date('Y').'-000002', FranchiseApplication::query()->orderByDesc('id')->firstOrFail()->number);
    }
}
