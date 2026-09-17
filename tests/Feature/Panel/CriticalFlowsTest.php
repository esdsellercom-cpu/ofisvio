<?php

namespace Tests\Feature\Panel;

use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Content;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Payment;
use App\Models\SpaceAssignment;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Website;
use App\Services\AssetService;
use App\Services\ContentCache;
use App\Services\MemberCenterService;
use App\Services\SeoService;
use App\Services\SpaceService;
use App\Services\SubscriptionService;
use Database\Seeders\LocationSeeder;
use Database\Seeders\NotificationRuleSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Üretim denetimi (faz 52) — uçtan uca kritik akışlar, gerçek HTTP rotalarıyla:
 *  A) Üye oluştur → hizmet ekle → sözleşme → tahsis (+demirbaş) → fatura → tahsilat → makbuz; veri bütünlüğü
 *     (tahsis bitince demirbaş iadesi, tahsilat iptali fiziksel silmez, profil tutarlı, aktivite izi).
 *  B) Blog yazısı oluştur → SEO/GEO/şema → iç bağlantı → yayınla; vitrin head/JSON-LD/sitemap/iç bağlantı denetimi.
 */
class CriticalFlowsTest extends TestCase
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
    public function uye_hizmet_sozlesme_tahsis_fatura_tahsilat_makbuz_akisi_tutarli_ve_geri_alinabilir(): void
    {
        $org = $this->organization('Acme');
        $co = $this->company($org, 'Acme A.Ş.');
        $this->owner($org, $co);
        $admin = $this->staff('system_admin');
        $as = fn () => $this->actingAs($admin)->withContext($org);
        $location = Location::query()->firstOrFail();
        $space = app(SpaceService::class)->create($admin, $location, ['kind' => 'office', 'name' => 'Ofis 204', 'capacity' => 1, 'monthly_price' => 0]);
        $laptop = app(AssetService::class)->create($admin, $location, ['name' => 'Laptop', 'category' => 'electronics', 'code' => 'LP-1']);
        $plan = app(SubscriptionService::class)->createPlan($admin, ['name' => 'Sanal Ofis', 'price' => '1000', 'period' => 'monthly', 'is_active' => 1]);

        // 1) Üye oluştur (profil + üye no)
        $as()->post("/panel/uyeler/{$co->id}", ['first_name' => 'Ali', 'last_name' => 'Veli', 'email' => 'ali@acme.test', 'phone' => '0555 111 22 33', 'membership_type' => 'sanal_ofis', 'status' => 'active'])->assertRedirect()->assertSessionHasNoErrors();
        $member = UserRole::query()->where('user_id', User::query()->where('email', 'ali@acme.test')->value('id'))->firstOrFail();
        $show = "/panel/uyeler/{$member->id}";

        // 2) Hizmet ekle (mevcut üyelik rotası, profile dönüş, ilk dönem faturası)
        $as()->post('/panel/uyelikler', ['company_id' => $co->id, 'plan_id' => $plan->id, 'starts_on' => '2026-09-01', 'months' => 12, 'issue_invoice' => 1, 'return' => $show.'?sekme=hizmet'])->assertRedirect($show.'?sekme=hizmet')->assertSessionHasNoErrors();
        $sub = Subscription::withoutTenantScope()->where('company_id', $co->id)->firstOrFail();
        $this->assertSame('active', $sub->status);
        $subInvoice = Invoice::withoutTenantScope()->where('subscription_id', $sub->id)->firstOrFail();

        // 3) Sözleşme
        $as()->post("/panel/uyeler/{$co->id}/{$member->id}/sozlesme", ['type' => 'sanal_ofis', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31', 'status' => 'active'])->assertRedirect()->assertSessionHasNoErrors();

        // 4) Tahsis + demirbaş (envanter rotası, profile dönüş)
        $as()->post('/panel/alanlar/tahsis', ['space_id' => $space->id, 'company_id' => $co->id, 'subscription_id' => $sub->id, 'user_id' => $member->user_id, 'starts_on' => '2026-09-01', 'asset_ids' => [$laptop->id], 'return' => $show.'?sekme=tahsis'])->assertRedirect($show.'?sekme=tahsis')->assertSessionHasNoErrors();
        $assignment = SpaceAssignment::withoutTenantScope()->where('company_id', $co->id)->firstOrFail();
        $this->assertSame('assigned', $laptop->fresh()->status);
        $this->assertSame($assignment->id, $laptop->fresh()->space_assignment_id);

        // 5) Ek fatura (fatura rotası) → 6) tahsilat (tahsilat rotası) → 7) makbuz
        $as()->post('/panel/faturalar', ['company_id' => $co->id, 'description' => 'Kurulum ücreti', 'subtotal' => '500', 'tax_rate' => 20, 'issue' => 1, 'return' => $show.'?sekme=finans'])->assertRedirect($show.'?sekme=finans')->assertSessionHasNoErrors();
        $setup = Invoice::withoutTenantScope()->where('description', 'Kurulum ücreti')->firstOrFail();
        $this->assertSame(60000, $setup->total);
        $as()->from($show)->post('/panel/tahsilat/tahsilat', ['company_id' => $co->id, 'invoice_id' => $setup->id, 'amount' => '-100', 'method' => 'cash', 'paid_on' => '2026-09-18'])->assertSessionHasErrors('amount'); // negatif tutar
        $as()->from($show)->post('/panel/tahsilat/tahsilat', ['company_id' => $co->id, 'invoice_id' => $setup->id, 'amount' => '9999', 'method' => 'cash', 'paid_on' => '2026-09-18'])->assertSessionHasErrors('amount'); // bakiyeyi aşan
        $as()->post('/panel/tahsilat/tahsilat', ['company_id' => $co->id, 'invoice_id' => $setup->id, 'amount' => '600', 'method' => 'transfer', 'paid_on' => '2026-09-18', 'then' => 'receipt', 'return' => $show])->assertRedirect();
        $payment = Payment::withoutTenantScope()->where('invoice_id', $setup->id)->firstOrFail();
        $receipt = Document::query()->where('payment_id', $payment->id)->where('kind', 'receipt')->firstOrFail();
        $this->assertSame('paid', $setup->fresh()->status);

        // Profil: tüm parçalar tek yerde ve tutarlı.
        $finance = app(MemberCenterService::class)->finance($co);
        $this->assertSame($subInvoice->total + $setup->total, $finance['invoiced']);
        $this->assertSame(60000, $finance['paid']);
        $this->assertSame($subInvoice->outstanding(), $finance['remaining']);
        $page = $as()->get($show)->assertOk();
        $page->assertSee('Sanal Ofis')->assertSee('Ofis 204')->assertSee('SOZ-2026-000001')->assertSee('1 demirbaş')->assertSee('18.09.2026');
        $as()->get($show.'?sekme=belge')->assertOk()->assertSee($receipt->number)->assertSee($setup->number)->assertSee('SOZ-2026-000001');
        $as()->get($show.'?sekme=tahsis')->assertOk()->assertSee('Laptop');

        // Bütünlük: tahsis bitince demirbaş iade; tahsilat iptali silmez, bakiyeyi geri alır; audit izi tam.
        $as()->post("/panel/alanlar/tahsis/{$assignment->id}/bitir", ['return' => $show.'?sekme=tahsis'])->assertRedirect($show.'?sekme=tahsis');
        $this->assertSame('ended', $assignment->fresh()->status);
        $this->assertSame('available', $laptop->fresh()->status);
        $this->assertNull($laptop->fresh()->space_assignment_id);
        $as()->post("/panel/tahsilat/tahsilat/{$payment->id}/iptal", ['reason' => 'Yanlış fatura'])->assertRedirect();
        $this->assertSame('cancelled', $payment->fresh()->status, 'Tahsilat fiziksel silinmez.');
        $this->assertSame(1, Payment::withoutTenantScope()->count());
        $this->assertSame('issued', $setup->fresh()->status);
        $this->assertSame(0, app(MemberCenterService::class)->finance($co)['paid']);
        $this->assertTrue(Asset::query()->whereKey($laptop->id)->exists());
        foreach (['member.created', 'membership.invited', 'subscription.created', 'contract.created', 'space.assigned', 'asset.assigned', 'invoice.created', 'invoice.issued', 'payment.recorded', 'document.created', 'space.assignment_ended', 'asset.released', 'payment.cancelled'] as $action) {
            $this->assertTrue(AuditLog::query()->where('action', $action)->exists(), "audit: {$action}");
        }
        $log = AuditLog::query()->where('action', 'payment.cancelled')->firstOrFail();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertNotNull($log->ip);
        $as()->get($show.'?sekme=aktivite')->assertOk()->assertSee('Tahsilat iptal edildi')->assertSee('Demirbaş iade alındı');
    }

    #[Test]
    public function blog_yazisi_seo_geo_ic_baglanti_sema_ve_yayin_akisi_vitrine_dogru_yansir(): void
    {
        $admin = $this->staff('system_admin');
        $website = Website::query()->default()->firstOrFail();

        // Hedef sayfa yayında (iç bağlantı için).
        $this->actingAs($admin)->post('/panel/icerik/yayinla', ['website_id' => $website->id, 'kind' => 'page', 'title' => 'Sanal ofis hizmeti', 'body' => "## Sanal ofis\n\nYasal adres hizmeti."])->assertRedirect();
        $target = Content::query()->where('slug', 'sanal-ofis-hizmeti')->firstOrFail();
        $this->assertSame('PUBLISHED', $target->status->value);

        // Yazı: SEO + GEO + şema + iç bağlantı ile oluştur (taslak) → analiz skoru → yayınla.
        $this->actingAs($admin)->post('/panel/icerik', [
            'website_id' => $website->id, 'kind' => 'post', 'title' => 'Sanal ofis ile şirket kurmanın 5 adımı', 'category' => 'Sanal Ofis', 'tags' => 'sanal ofis, tescil',
            'excerpt' => 'Sanal ofis ile şirket kurarken adres, tescil ve posta yönetimi adımlarını tek rehberde topladık.',
            'body' => "Sanal ofis ile şirket kurmak isteyenler için giriş.\n\n## 1. Adres seçimi\n\n[Sanal ofis hizmeti](/sanal-ofis-hizmeti) sayfasında paketler.\n\n## 2. Tescil\n\nMetin. ".str_repeat('sanal ofis tescil adımı ', 60),
            'meta_title' => 'Sanal ofis ile şirket kurmanın 5 adımı | Rehber', 'meta_description' => 'Sanal ofis ile şirket kurmak: adres seçimi, tescil, posta ve toplantı odası adımları; aynı gün başlangıç.',
            'focus_keyword' => 'sanal ofis', 'schema_types' => ['Article', 'FAQPage'], 'geo' => ['summary' => 'Sanal ofis ile şirket kurma rehberi.', 'faq' => 'Sanal ofis ile şirket kurulur mu? | Evet, tescil adresi olarak kullanılır.
Kaç günde başlar? | Aynı gün.'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $post = Content::query()->where('slug', 'sanal-ofis-ile-sirket-kurmanin-5-adimi')->firstOrFail();
        $this->assertGreaterThanOrEqual(70, $post->seo_score);
        $this->assertTrue($post->geoReady());
        $audit = app(SeoService::class)->linkAudit($website, (string) $post->body, $post);
        $this->assertSame([], $audit['broken']);
        $this->assertSame(1, $audit['outbound']);

        $this->actingAs($admin)->put("/panel/icerik/{$post->id}/kaydet-ve-yayinla", ['title' => $post->title, 'body' => $post->body, 'excerpt' => $post->excerpt, 'category' => 'Sanal Ofis', 'tags' => 'sanal ofis, tescil', 'meta_title' => $post->meta_title, 'meta_description' => $post->meta_description, 'focus_keyword' => 'sanal ofis', 'schema_types' => ['Article', 'FAQPage'], 'geo' => ['summary' => 'Sanal ofis ile şirket kurma rehberi.', 'faq' => 'Sanal ofis ile şirket kurulur mu? | Evet, tescil adresi olarak kullanılır.
Kaç günde başlar? | Aynı gün.']])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('PUBLISHED', $post->fresh()->status->value);
        app(ContentCache::class)->invalidate($website);

        // Vitrin: head, JSON-LD (Article + FAQPage from GEO), iç bağlantı, sitemap.
        $html = $this->get('/blog/'.$post->slug)->assertOk()->getContent();
        $this->assertStringContainsString('<title>Sanal ofis ile şirket kurmanın 5 adımı | Rehber', $html);
        $this->assertStringContainsString('href="/sanal-ofis-hizmeti"', $html);
        $this->assertStringContainsString('"@type":"Article"', str_replace(' ', '', $html));
        $this->assertStringContainsString('FAQPage', $html);
        $this->assertStringContainsString('Sanal ofis ile şirket kurulur mu?', $html);
        $this->assertStringContainsString('rel="canonical"', $html);
        $this->assertStringNotContainsString('noindex', $html);
        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/blog/'.$post->slug, $sitemap);
        $this->assertStringContainsString('/sanal-ofis-hizmeti', $sitemap);
        $this->assertGreaterThanOrEqual(1, app(SeoService::class)->linkAudit($website, (string) $target->body, $target)['inbound'], 'Hedef sayfaya gelen bağlantı sayılır (yazı + menü).');
        $this->actingAs($admin)->get('/panel/icerik?kind=post')->assertOk()->assertSee('hazır')->assertSee((string) $post->fresh()->seo_score);
        $this->assertTrue(AuditLog::query()->where('action', 'content.status_changed')->where('entity_id', $post->id)->exists());
    }
}
