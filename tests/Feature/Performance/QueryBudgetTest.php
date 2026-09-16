<?php

namespace Tests\Feature\Performance;

use App\Enums\KycDocumentType;
use App\Models\KycDocument;
use App\Models\Organization;
use App\Models\User;
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
        'vitrin' => 6,
        'panel.dashboard' => 14,
        'panel.companies' => 14,
        'panel.kyc' => 18,
        'panel.content' => 8,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(LocationSeeder::class);
        $this->seed(WebsiteSeeder::class);
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
}
