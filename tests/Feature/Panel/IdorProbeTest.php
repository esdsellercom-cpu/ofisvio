<?php

namespace Tests\Feature\Panel;

use App\Models\Contract;
use App\Models\Document;
use App\Models\UserRole;
use App\Services\InvoiceService;
use App\Services\MemberCenterService;
use App\Services\SubscriptionService;
use Database\Seeders\LocationSeeder;
use Database\Seeders\NotificationRuleSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Üretim denetimi (faz 52) — IDOR: şirket kapsamlı rol (sahip) başka şirketin/organizasyonun kaydına id
 * değiştirerek erişemez; global personel görür. Fatura, tahsilat/belge, üyelik, sözleşme dosyası, üye profili,
 * ek harcama, şirket sayfaları.
 */
class IdorProbeTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(LocationSeeder::class);
        $this->seed(WebsiteSeeder::class);
        $this->seed(NotificationRuleSeeder::class);
        Carbon::setTestNow('2026-09-18 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function sirket_kapsamli_kullanici_baska_sirketin_finans_uyelik_sozlesme_ve_uye_kayitlarina_ulasamaz(): void
    {
        $org = $this->organization('Acme');
        $a = $this->company($org, 'Acme A.Ş.');
        $b = $this->company($org, 'Beta Ltd.');
        $ownerA = $this->owner($org, $a);
        $ownerB = $this->owner($org, $b);
        $other = $this->organization('Globex');
        $c = $this->company($other, 'Globex');
        $ownerC = $this->owner($other, $c);
        $admin = $this->staff('system_admin');
        $finance = $this->staff('finance_admin');

        // B ve C'nin kayıtları (servislerle, gerçek yollardan).
        $invB = app(InvoiceService::class)->create($finance, $b, ['description' => 'B faturası', 'subtotal' => '100', 'tax_rate' => 20, 'due_on' => '2026-09-10', 'issue' => true]);
        $this->actingAs($finance)->withContext($org)->post('/panel/tahsilat/tahsilat', ['company_id' => $b->id, 'invoice_id' => $invB->id, 'amount' => '50', 'method' => 'cash', 'paid_on' => '2026-09-18', 'then' => 'receipt'])->assertRedirect();
        $docB = Document::query()->where('kind', 'receipt')->firstOrFail();
        $plan = app(SubscriptionService::class)->createPlan($admin, ['name' => 'Plan', 'slug' => 'plan', 'price' => '100', 'period' => 'monthly', 'is_active' => 1]);
        $subB = app(SubscriptionService::class)->create($finance, $b, $plan, ['starts_on' => '2026-09-01', 'months' => 12]);
        $memberB = UserRole::query()->where('user_id', $ownerB->id)->where('company_id', $b->id)->firstOrFail();
        $contractB = app(MemberCenterService::class)->createContract($admin, $b, $memberB, ['type' => 'uyelik', 'starts_on' => '2026-09-01', 'ends_on' => null, 'status' => 'active', 'note' => null]);
        $memberC = UserRole::query()->where('user_id', $ownerC->id)->where('company_id', $c->id)->firstOrFail();

        $asA = fn () => $this->actingAs($ownerA)->withContext($org);

        // Şirket A sahibi → B'nin kayıtları: 404 (varlık sızmaz) ya da 403.
        $this->assertContains($asA()->get("/panel/faturalar/{$invB->id}")->getStatusCode(), [403, 404], 'fatura');
        $this->assertContains($asA()->get("/panel/tahsilat/belge/{$docB->id}")->getStatusCode(), [403, 404], 'makbuz');
        $this->assertContains($asA()->get("/panel/tahsilat/belge/{$docB->id}/pdf")->getStatusCode(), [403, 404], 'makbuz pdf');
        $this->assertContains($asA()->get("/panel/uyelikler/{$subB->id}")->getStatusCode(), [403, 404], 'üyelik');
        $this->assertContains($asA()->get("/panel/uyeler/{$memberB->id}")->getStatusCode(), [403, 404], 'üye profili (aynı org, başka şirket)');
        $this->assertContains($asA()->get("/panel/uyeler/{$memberB->id}/sozlesme/{$contractB->id}/dosya")->getStatusCode(), [403, 404], 'sözleşme dosyası');
        $this->assertContains($asA()->get("/panel/sirketler/{$b->id}")->getStatusCode(), [403, 404], 'şirket');
        $this->assertContains($asA()->get("/panel/sirketler/{$b->id}/faturalar/{$invB->id}")->getStatusCode(), [403, 404], 'şirket faturası');
        $this->assertContains($asA()->post("/panel/faturalar/{$invB->id}/tahsilat", ['amount' => '10', 'method' => 'cash', 'paid_on' => '2026-09-18'])->getStatusCode(), [403, 404], 'başkasının faturasına tahsilat');
        $this->assertContains($asA()->post("/panel/uyeler/{$b->id}/{$memberB->id}/harcama", ['kind' => 'other', 'description' => 'x', 'amount' => '1', 'charged_on' => '2026-09-18', 'billing' => 'none'])->getStatusCode(), [403, 404], 'başkasının üyesine ek harcama');
        $this->assertContains($asA()->put("/panel/uyeler/{$b->id}/{$memberB->id}", ['first_name' => 'X', 'last_name' => 'Y'])->getStatusCode(), [403, 404], 'başkasının üyesini düzenleme');
        // Kendi şirketinin rotasına başka şirketin üyesini geçirmek: 404.
        $this->assertSame(404, $asA()->put("/panel/uyeler/{$a->id}/{$memberB->id}", ['first_name' => 'X', 'last_name' => 'Y'])->getStatusCode());

        // Başka organizasyon: 404 (tenant sınırı).
        $this->assertContains($this->actingAs($ownerC)->withContext($other)->get("/panel/faturalar/{$invB->id}")->getStatusCode(), [403, 404]);
        $this->assertContains($this->actingAs($ownerC)->withContext($other)->get("/panel/uyeler/{$memberB->id}")->getStatusCode(), [403, 404]);
        $this->assertContains($asA()->get("/panel/uyeler/{$memberC->id}")->getStatusCode(), [403, 404]);

        // Global personel görür; sahip kendi kaydını görür.
        $this->actingAs($finance)->withContext($org)->get("/panel/faturalar/{$invB->id}")->assertOk();
        $this->actingAs($ownerB)->withContext($org)->get("/panel/sirketler/{$b->id}/faturalar/{$invB->id}")->assertOk();
        $this->actingAs($ownerB)->withContext($org)->get("/panel/uyeler/{$memberB->id}")->assertOk();
        $this->assertSame(1, Contract::withoutTenantScope()->count());
    }
}
