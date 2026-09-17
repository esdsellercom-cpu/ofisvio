<?php

namespace Tests\Feature\Performance;

use App\Enums\ContentStatus;
use App\Enums\KycDocumentType;
use App\Models\Content;
use App\Models\KycDocument;
use App\Models\Organization;
use App\Models\User;
use App\Models\Website;
use App\Services\ContentCache;
use App\Services\SettingsService;
use App\Services\TenantContext;
use Database\Seeders\LocationSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Panel\CreatesTenantFixtures;
use Tests\TestCase;

/**
 * Faz 11 — Performance Foundation: sorgu bütçesi.
 *
 * Süre ölçümü CI'da gürültülüdür; SORGU SAYISI deterministiktir ve N+1'i
 * yakalar. İki kural:
 *   1. Liste sayfalarının sorgu sayısı satır sayısıyla BÜYÜMEZ (2 kayıt ile
 *      8 kayıt aynı sayıda sorgu). Büyüyorsa N+1 vardır.
 *   2. Anahtar sayfaların sorgu sayısı bir üst sınırı aşmaz. Sınırı aşan
 *      değişiklik bilinçli olarak burayı güncellemek zorundadır (§76 ruhu:
 *      sessiz gerileme yok).
 */
class QueryBudgetTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    /**
     * Sayfa => izin verilen en fazla sorgu. 16 Eylül 2026 ölçümü: vitrin 5,
     * dashboard 11, şirketler 11, KYC 14, içerik 6 (öncesi: 126 / 62 — istek
     * başına yetki+tenant memo'su ve toplu KYC özeti ile). Pay ~%20.
     */
    private const BUDGET = [
        'vitrin' => 12, // booking engine: odalar + lokasyonları (2), onay politikası ayarı (1), hizmetler (1, önbellekli) + lokasyon→hizmet ilişkisi (1) — sahte kart yerine canlı veri
        'panel.dashboard' => 15, // +1 bildirim zili (okunmamış sayısı, istek başına bir kez)
        'panel.companies' => 14,
        'panel.kyc' => 18,
        'panel.content' => 9, // +1 bildirim zili
        'panel.calendar' => 13, // +1 bildirim zili
        'site.post' => 9, // +1 sayfa kurucu: üst menü yayınlanmış bölümlerden (revizyon okuma, önbellekli)
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(LocationSeeder::class);
        $this->seed(WebsiteSeeder::class);
        // Ayar önbelleği (settings:all) ilk istekte bir kez dolar; sayım o tek seferlik yüklemeyi değil sayfa maliyetini ölçer.
        app(SettingsService::class)->string('general.currency');
    }

    private function countQueries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    /** @return array{0: Organization, 1: User} */
    private function orgWithCompanies(int $count): array
    {
        $org = $this->organization('Org'.$count);
        $companies = [];

        for ($i = 1; $i <= $count; $i++) {
            $companies[] = $this->company($org, "Şirket {$i} ({$count})");
        }

        $owner = $this->owner($org, ...$companies);

        app(TenantContext::class)->runAsSystem(function () use ($companies) {
            foreach ($companies as $company) {
                KycDocument::create([
                    'company_id' => $company->id, 'type' => KycDocumentType::TAX_CERTIFICATE, 'status' => 'PENDING',
                    'original_filename' => 'v.pdf', 'storage_path' => 'kyc/x.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10,
                ]);
            }
        });

        return [$org, $owner];
    }

    #[Test]
    public function vitrin_sorgu_butcesinde(): void
    {
        $n = $this->countQueries(fn () => $this->get('/')->assertOk());

        $this->assertLessThanOrEqual(self::BUDGET['vitrin'], $n, "Vitrin {$n} sorgu çalıştırdı.");
    }

    #[Test]
    public function panel_listeleri_satir_sayisiyla_buyumez(): void
    {
        [$small, $ownerSmall] = $this->orgWithCompanies(2);
        [$large, $ownerLarge] = $this->orgWithCompanies(8);

        foreach (['/panel' => 'panel.dashboard', '/panel/sirketler' => 'panel.companies'] as $url => $key) {
            $nSmall = $this->countQueries(fn () => $this->actingAs($ownerSmall)->withContext($small)->get($url)->assertOk());
            $nLarge = $this->countQueries(fn () => $this->actingAs($ownerLarge)->withContext($large)->get($url)->assertOk());

            $this->assertSame($nSmall, $nLarge, "{$url}: 2 şirkette {$nSmall}, 8 şirkette {$nLarge} sorgu — N+1.");
            $this->assertLessThanOrEqual(self::BUDGET[$key], $nLarge, "{$url} {$nLarge} sorgu çalıştırdı.");
        }
    }

    #[Test]
    public function kyc_sayfasi_belge_sayisiyla_buyumez(): void
    {
        $org = $this->organization('Kyc');
        $company = $this->company($org, 'Kyc Ltd');
        $owner = $this->owner($org, $company);

        $addDocs = function (int $count) use ($company) {
            app(TenantContext::class)->runAsSystem(function () use ($company, $count) {
                foreach (KycDocumentType::cases() as $i => $type) {
                    if ($i >= $count) {
                        break;
                    }
                    KycDocument::create([
                        'company_id' => $company->id, 'type' => $type, 'status' => 'PENDING',
                        'original_filename' => 'v.pdf', 'storage_path' => "kyc/{$i}.pdf", 'mime_type' => 'application/pdf', 'size_bytes' => 10,
                    ]);
                }
            });
        };

        $addDocs(1);
        $n1 = $this->countQueries(fn () => $this->actingAs($owner)->withContext($org)->get("/panel/sirketler/{$company->id}/kyc")->assertOk());
        $addDocs(6);
        $n6 = $this->countQueries(fn () => $this->actingAs($owner)->withContext($org)->get("/panel/sirketler/{$company->id}/kyc")->assertOk());

        $this->assertSame($n1, $n6, "KYC sayfası: 1 belgede {$n1}, 6 belgede {$n6} sorgu — N+1.");
        $this->assertLessThanOrEqual(self::BUDGET['panel.kyc'], $n6);
    }

    #[Test]
    public function icerik_listesi_butcede(): void
    {
        $admin = $this->staff('system_admin');
        $n = $this->countQueries(fn () => $this->actingAs($admin)->get('/panel/icerik')->assertOk());

        $this->assertLessThanOrEqual(self::BUDGET['panel.content'], $n, "İçerik listesi {$n} sorgu çalıştırdı.");
    }

    #[Test]
    public function takvim_ve_yazi_sayfasi_kayit_sayisindan_bagimsiz(): void
    {
        $admin = $this->staff('system_admin');
        $site = Website::query()->default()->firstOrFail();

        $make = function (int $i) use ($site): Content {
            $c = Content::create(['website_id' => $site->id, 'kind' => 'post', 'slug' => "yazi-{$i}", 'title' => "Yazı {$i}", 'body' => 'Gövde.', 'category' => $i % 2 ? 'A' : 'B']);
            $c->forceFill(['status' => ContentStatus::PUBLISHED, 'published_at' => now()->subDays($i)])->save();

            return $c;
        };

        $first = $make(1);
        $c1 = $this->countQueries(fn () => $this->actingAs($admin)->get('/panel/icerik/takvim')->assertOk());
        $p1 = $this->countQueries(fn () => $this->get('/blog/yazi-1')->assertOk());

        foreach (range(2, 12) as $i) {
            $make($i);
        }
        app(ContentCache::class)->invalidate($site);

        $c12 = $this->countQueries(fn () => $this->actingAs($admin)->get('/panel/icerik/takvim')->assertOk());
        $p12 = $this->countQueries(fn () => $this->get('http://localhost/blog/'.$first->slug)->assertOk()->assertSee('İlgili yazılar'));

        $this->assertSame($c1, $c12, "Takvim: 1 içerikte {$c1}, 12 içerikte {$c12} sorgu — N+1.");
        $this->assertSame($p1, $p12, "Yazı sayfası: 1 yazıda {$p1}, 12 yazıda {$p12} sorgu — N+1.");
        $this->assertLessThanOrEqual(self::BUDGET['panel.calendar'], $c12, "Takvim {$c12} sorgu çalıştırdı.");
        $this->assertLessThanOrEqual(self::BUDGET['site.post'], $p12, "Yazı sayfası {$p12} sorgu çalıştırdı.");
    }
}
