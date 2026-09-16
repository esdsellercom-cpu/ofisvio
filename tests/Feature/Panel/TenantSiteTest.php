<?php

namespace Tests\Feature\Panel;

use App\Enums\ContentStatus;
use App\Models\Company;
use App\Models\Content;
use App\Models\ContentDraft;
use App\Models\Organization;
use App\Models\Website;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 10 — Müşteri sitesi: organizasyonun web sitesindeki içerik müşteri
 * panelinden düzenlenir, incelemeye gönderilir, ZAMANLANIR (müşteri rolleri
 * content.publish taşımaz). Tenant sınırı: başka organizasyonun içeriği 404.
 * Çalışma taslağı da zamanlanabilir; zamanlayıcı zamanı gelince birleştirir.
 */
class TenantSiteTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(WebsiteSeeder::class);
        Carbon::setTestNow('2026-09-16 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Organization, 1: Company, 2: Website} */
    private function tenant(string $name, string $domain): array
    {
        $org = $this->organization($name);
        $company = $this->company($org, $name.' A.Ş.');
        $site = Website::create(['organization_id' => $org->id, 'name' => $name, 'slug' => str($name)->slug()->toString(), 'domain' => $domain]);

        return [$org, $company, $site];
    }

    /** @param  array<string, mixed>  $attrs */
    private function page(Website $site, string $title, ContentStatus $status = ContentStatus::DRAFT, array $attrs = []): Content
    {
        $content = Content::create(['website_id' => $site->id, 'kind' => 'page', 'slug' => str($title)->slug()->toString(), 'title' => $title, 'body' => "## $title\n\nYayındaki metin."]);
        $content->forceFill(array_merge(['status' => $status, 'published_at' => $status === ContentStatus::PUBLISHED ? now()->subDay() : null], $attrs))->save();

        return $content;
    }

    #[Test]
    public function sahip_kendi_sitesini_duzenler_zamanlar_ve_baska_organizasyonu_goremez(): void
    {
        [$acme, $acmeCo, $acmeSite] = $this->tenant('Acme', 'acme.example');
        [$beta, $betaCo, $betaSite] = $this->tenant('Beta', 'beta.example');
        $owner = $this->owner($acme, $acmeCo);

        $draft = $this->page($acmeSite, 'Hakkımızda');
        $foreign = $this->page($betaSite, 'Beta Hakkında');

        // Şirket sayfasında bağlantı, liste yalnız kendi sitesi.
        $this->actingAs($owner)->withContext($acme)->get("/panel/sirketler/{$acmeCo->id}")->assertOk()->assertSee("/panel/sirketler/{$acmeCo->id}/site", false);
        $this->actingAs($owner)->withContext($acme)->get("/panel/sirketler/{$acmeCo->id}/site")->assertOk()
            ->assertSee('acme.example')->assertSee('Hakkımızda')->assertDontSee('Beta Hakkında');

        // Başka organizasyonun içeriği: id tahmini 404 (403 varlığı sızdırır).
        $this->actingAs($owner)->withContext($acme)->get("/panel/sirketler/{$acmeCo->id}/site/{$foreign->id}")->assertNotFound();
        $this->actingAs($owner)->withContext($acme)->put("/panel/sirketler/{$acmeCo->id}/site/{$foreign->id}", ['title' => 'Ele geçirildi', 'body' => 'x'])->assertNotFound();
        $this->assertSame('Beta Hakkında', $foreign->fresh()->title);

        // Başka organizasyonun şirketi üzerinden de erişilemez (tenant context uyuşmaz -> 404).
        $this->actingAs($owner)->withContext($acme)->get("/panel/sirketler/{$betaCo->id}/site")->assertNotFound();

        // Düzenle -> incelemeye gönder -> zamanla. Doğrudan yayın rotası YOK.
        $this->actingAs($owner)->withContext($acme)->get("/panel/sirketler/{$acmeCo->id}/site/{$draft->id}/duzenle")->assertOk()->assertSee('Taslağı düzenle');
        $this->actingAs($owner)->withContext($acme)->put("/panel/sirketler/{$acmeCo->id}/site/{$draft->id}", ['title' => 'Hakkımızda', 'body' => "## Biz\n\nAcme metni."])
            ->assertRedirect("/panel/sirketler/{$acmeCo->id}/site/{$draft->id}");
        $this->actingAs($owner)->withContext($acme)->post("/panel/sirketler/{$acmeCo->id}/site/{$draft->id}/incelemeye-gonder")->assertRedirect();
        $this->assertSame(ContentStatus::IN_REVIEW, $draft->fresh()->status);
        $this->actingAs($owner)->withContext($acme)->post("/panel/sirketler/{$acmeCo->id}/site/{$draft->id}/yayinla")->assertNotFound(); // rota yok

        $this->actingAs($owner)->withContext($acme)->from("/panel/sirketler/{$acmeCo->id}/site/{$draft->id}")
            ->post("/panel/sirketler/{$acmeCo->id}/site/{$draft->id}/zamanla", ['scheduled_for' => '2026-09-10 09:00'])
            ->assertSessionHasErrors('scheduled_for'); // geçmiş
        $this->actingAs($owner)->withContext($acme)->post("/panel/sirketler/{$acmeCo->id}/site/{$draft->id}/zamanla", ['scheduled_for' => '2026-09-17 09:00'])->assertRedirect();
        $this->assertSame(ContentStatus::SCHEDULED, $draft->fresh()->status);

        // Zamanı gelince yayına girer; müşteri sitesinde görünür, Ofisvio vitrininde görünmez.
        $this->get('http://acme.example/hakkimizda')->assertNotFound();
        Carbon::setTestNow('2026-09-17 09:01:00');
        $this->artisan('content:publish-scheduled')->assertSuccessful();
        $this->assertSame(ContentStatus::PUBLISHED, $draft->fresh()->status);
        $this->get('http://acme.example/hakkimizda')->assertOk()->assertSee('Acme metni.');
        $this->get('http://localhost/hakkimizda')->assertNotFound();
    }

    #[Test]
    public function yayindaki_sayfa_calisma_taslagiyla_zamanlanir_ve_zamanlayici_birlestirir(): void
    {
        [$acme, $acmeCo, $acmeSite] = $this->tenant('Acme', 'acme.example');
        $owner = $this->owner($acme, $acmeCo);
        $live = $this->page($acmeSite, 'İletişim', ContentStatus::PUBLISHED);
        $base = "/panel/sirketler/{$acmeCo->id}/site/{$live->id}";

        $this->actingAs($owner)->withContext($acme)->get($base)->assertOk()->assertSee('Çalışma taslağı aç');
        $this->actingAs($owner)->withContext($acme)->post("$base/taslak")->assertRedirect("$base/taslak/duzenle");
        $this->actingAs($owner)->withContext($acme)->get("$base/taslak/duzenle")->assertOk()->assertSee('Çalışma taslağını düzenle');
        $this->actingAs($owner)->withContext($acme)->put("$base/taslak", ['title' => 'İletişim', 'body' => 'Yeni adres.'])->assertRedirect($base);
        $this->actingAs($owner)->withContext($acme)->post("$base/taslak/incelemeye-gonder")->assertRedirect();
        $this->actingAs($owner)->withContext($acme)->post("$base/taslak/zamanla", ['scheduled_for' => '2026-09-18 08:00'])->assertRedirect($base)->assertSessionHasNoErrors();

        $draft = ContentDraft::where('content_id', $live->id)->firstOrFail();
        $this->assertSame(ContentStatus::SCHEDULED, $draft->status);
        $this->get('http://acme.example/iletisim')->assertOk()->assertSee('Yayındaki metin.')->assertDontSee('Yeni adres.');

        // Zamanlanmış taslak personel takviminde birleşme günündedir.
        $admin = $this->staff('system_admin');
        $this->actingAs($admin)->get('/panel/icerik/takvim?website='.$acmeSite->id)->assertOk()->assertSee('↻ İletişim')->assertDontSee('Gecikmiş zamanlama');

        // Zamanlamayı iptal -> taslağa döner; yeniden zamanla.
        $this->actingAs($owner)->withContext($acme)->post("$base/taslak/taslaga-al")->assertRedirect();
        $this->assertSame(ContentStatus::DRAFT, $draft->fresh()->status);
        $this->assertNull($draft->fresh()->scheduled_for);
        $this->actingAs($owner)->withContext($acme)->post("$base/taslak/incelemeye-gonder")->assertRedirect();
        $this->actingAs($owner)->withContext($acme)->post("$base/taslak/zamanla", ['scheduled_for' => '2026-09-18 08:00'])->assertRedirect();

        // Zamanı geçti, zamanlayıcı koşmadı: gecikmiş uyarısı (çalışma taslağı).
        Carbon::setTestNow('2026-09-18 08:05:00');
        $this->actingAs($admin)->get('/panel/icerik/takvim?website='.$acmeSite->id)->assertOk()->assertSee('Gecikmiş zamanlama')->assertSee('(çalışma taslağı)');

        $this->artisan('content:publish-scheduled')->assertSuccessful();
        $this->assertNull(ContentDraft::find($draft->id));
        $this->assertSame(ContentStatus::PUBLISHED, $live->fresh()->status);
        $this->get('http://acme.example/iletisim')->assertOk()->assertSee('Yeni adres.')->assertDontSee('Yayındaki metin.');
    }

    #[Test]
    public function izinler_matrise_gore_uygulanir(): void
    {
        [$acme, $acmeCo, $acmeSite] = $this->tenant('Acme', 'acme.example');
        $draft = $this->page($acmeSite, 'Ekip');
        $live = $this->page($acmeSite, 'Hizmetler', ContentStatus::PUBLISHED);
        $liveBase = "/panel/sirketler/{$acmeCo->id}/site/{$live->id}";

        // company_admin: content.edit var; review/schedule yok.
        $admin = $this->member($acme);
        $this->grantRole($admin, 'company_admin', ['company_id' => $acmeCo->id]);

        $this->actingAs($admin)->withContext($acme)->get("/panel/sirketler/{$acmeCo->id}/site")->assertOk();
        $this->actingAs($admin)->withContext($acme)->post("/panel/sirketler/{$acmeCo->id}/site/{$draft->id}/incelemeye-gonder")->assertRedirect();
        $this->actingAs($admin)->withContext($acme)->post("/panel/sirketler/{$acmeCo->id}/site/{$draft->id}/zamanla", ['scheduled_for' => '2026-09-20 09:00'])->assertForbidden();
        $this->actingAs($admin)->withContext($acme)->post("/panel/sirketler/{$acmeCo->id}/site/{$draft->id}/geri-gonder", ['note' => 'x'])->assertForbidden();

        // Onay gerektiren sayfa: sahip zamanlayamaz (Ofisvio onayı şart).
        $legal = $this->page($acmeSite, 'KVKK Aydınlatma', ContentStatus::IN_REVIEW, ['requires_approval' => true]);
        $owner = $this->owner($acme, $acmeCo);
        $this->actingAs($owner)->withContext($acme)->get("/panel/sirketler/{$acmeCo->id}/site/{$legal->id}")->assertOk()->assertSee('Ofisvio onayı bekleniyor');
        $this->actingAs($owner)->withContext($acme)->from("/panel/sirketler/{$acmeCo->id}/site/{$legal->id}")
            ->post("/panel/sirketler/{$acmeCo->id}/site/{$legal->id}/zamanla", ['scheduled_for' => '2026-09-20 09:00'])
            ->assertSessionHasErrors('status');
        $this->assertSame(ContentStatus::IN_REVIEW, $legal->fresh()->status);

        // Aynı organizasyonun yalnızca üyesi (rolsüz): 403.
        $plain = $this->member($acme);
        $this->actingAs($plain)->withContext($acme)->get("/panel/sirketler/{$acmeCo->id}/site")->assertForbidden();

        // Personel rotası müşteri rolüyle kapalı kalır.
        $this->actingAs($owner)->withContext($acme)->get('/panel/icerik')->assertForbidden();
        $this->actingAs($owner)->withContext($acme)->post("/panel/icerik/{$live->id}/taslak")->assertForbidden();
        $this->actingAs($owner)->withContext($acme)->get($liveBase)->assertOk();
    }
}
