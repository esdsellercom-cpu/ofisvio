<?php

namespace Tests\Feature\Security;

use App\Enums\ContentStatus;
use App\Models\Company;
use App\Models\Content;
use App\Models\KycDocument;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Website;
use App\Services\TenantContext;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Panel\CreatesTenantFixtures;
use Tests\TestCase;

/**
 * Audit §18 — Tenant izolasyon matrisi.
 *
 *   Organization A / Company A1 / User A (owner)
 *   Organization B / Company B1 / User B (owner)
 *
 * User A, B'nin şirketine, KYC belgesine, üyelerine, sitesine/içeriğine,
 * SEO ve menü ayarlarına GET/POST/PUT/DELETE/indirme ile erişemez.
 * Beklenen: 404 (varlık sızdırmaz) ya da 403; hiçbir zaman 200/302-başarı.
 * Ayrıca A'nın oturumunda B organizasyonu context'i seçilemez.
 */
class TenantIsolationTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    private User $userA;

    private User $userB;

    private Organization $orgA;

    private Organization $orgB;

    private Company $companyA;

    private Company $companyB;

    private Website $siteB;

    private Content $pageB;

    private KycDocument $docB;

    private UserRole $roleB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(WebsiteSeeder::class);
        Storage::fake('private');

        $this->orgA = $this->organization('Org A');
        $this->orgB = $this->organization('Org B');
        $this->companyA = $this->company($this->orgA, 'A1 A.Ş.');
        $this->companyB = $this->company($this->orgB, 'B1 A.Ş.');
        $this->userA = $this->owner($this->orgA, $this->companyA);
        $this->userB = $this->owner($this->orgB, $this->companyB);

        $this->siteB = Website::create(['organization_id' => $this->orgB->id, 'name' => 'B Site', 'slug' => 'b-site', 'domain' => 'b.example']);
        $this->pageB = Content::create(['website_id' => $this->siteB->id, 'kind' => 'page', 'slug' => 'b-sayfa', 'title' => 'B Sayfa', 'body' => 'B gizli metin.']);
        $this->pageB->forceFill(['status' => ContentStatus::PUBLISHED, 'published_at' => now()->subDay()])->save();

        // B'nin KYC belgesi (kendi sahibi yükler).
        $this->actingAs($this->userB)->withContext($this->orgB)
            ->post("/panel/sirketler/{$this->companyB->id}/kyc", ['type' => 'TAX_CERTIFICATE', 'file' => UploadedFile::fake()->create('vergi.pdf', 20, 'application/pdf')]);
        $this->docB = app(TenantContext::class)->runAsSystem(fn () => KycDocument::query()->where('company_id', $this->companyB->id)->firstOrFail());
        $this->roleB = UserRole::query()->where('user_id', $this->userB->id)->whereNotNull('company_id')->firstOrFail();
        auth()->logout();
    }

    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> yöntem, yol, gövde */
    private function attempts(): array
    {
        $b = "/panel/sirketler/{$this->companyB->id}";

        return [
            'şirket GET' => ['GET', $b, []],
            'KYC GET' => ['GET', "$b/kyc", []],
            'KYC POST' => ['POST', "$b/kyc", ['type' => 'TAX_CERTIFICATE', 'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]],
            'KYC indir' => ['GET', "$b/kyc/{$this->docB->id}/indir", []],
            'KYC onayla' => ['POST', "$b/kyc/{$this->docB->id}/onayla", []],
            'üyeler GET' => ['GET', "$b/uyeler", []],
            'üye davet POST' => ['POST', "$b/uyeler", ['name' => 'X Y', 'email' => 'x@y.com', 'role' => 'employee']],
            'üye askıya al' => ['POST', "$b/uyeler/{$this->roleB->id}/askiya-al", []],
            'site GET' => ['GET', "$b/site", []],
            'site içerik GET' => ['GET', "$b/site/{$this->pageB->id}", []],
            'site içerik PUT' => ['PUT', "$b/site/{$this->pageB->id}", ['title' => 'Ele geçirildi', 'body' => 'x']],
            'site taslak POST' => ['POST', "$b/site/{$this->pageB->id}/taslak", []],
            'site taslak DELETE' => ['DELETE', "$b/site/{$this->pageB->id}/taslak", []],
            'site SEO GET' => ['GET', "$b/site/seo", []],
            'site SEO PUT' => ['PUT', "$b/site/seo/{$this->siteB->id}", ['seo_title_suffix' => 'hack', 'seo_locale' => 'tr_TR']],
            'site menü PUT' => ['PUT', "$b/site/menu/{$this->siteB->id}", ['nav' => [$this->pageB->id => ['order' => 1, 'show' => 0]]]],
            'site ayar PUT' => ['PUT', "$b/site/ayarlar/{$this->siteB->id}", ['contact_phone' => '1']],
            'site tema PUT' => ['PUT', "$b/site/tema/{$this->siteB->id}", ['theme' => 'gece']],
        ];
    }

    #[Test]
    public function kullanici_a_b_organizasyonunun_hicbir_kaynagina_erisemez(): void
    {
        $leaks = [];

        foreach ($this->attempts() as $label => [$method, $uri, $payload]) {
            $response = $this->actingAs($this->userA)->withContext($this->orgA)->call($method, $uri, $payload);
            $status = $response->getStatusCode();

            // 404 (tercih) ya da 403 kabul; başarı (2xx) ya da işlem sonrası yönlendirme (302) sızıntıdır.
            if (! in_array($status, [403, 404], true)) {
                $leaks[] = "{$label}: {$method} {$uri} → {$status}";
            }
        }

        $this->assertSame([], $leaks, "Tenant sızıntısı:\n".implode("\n", $leaks));

        // Veri değişmedi.
        $this->assertSame('B Sayfa', $this->pageB->fresh()->title);
        $this->assertSame('kum', $this->siteB->fresh()->theme);
        $this->assertNull($this->siteB->fresh()->contact_phone);
        $this->assertSame('active', $this->roleB->fresh()->status);
        $this->assertSame(1, app(TenantContext::class)->runAsSystem(fn () => KycDocument::query()->where('company_id', $this->companyB->id)->count()));
    }

    #[Test]
    public function kullanici_a_kendi_kaynaklarina_erisir_kontrol_grubu(): void
    {
        // Matris anlamlı olsun: aynı yollar kendi şirketi için açık.
        $a = "/panel/sirketler/{$this->companyA->id}";
        $this->actingAs($this->userA)->withContext($this->orgA)->get($a)->assertOk();
        $this->actingAs($this->userA)->withContext($this->orgA)->get("$a/kyc")->assertOk();
        $this->actingAs($this->userA)->withContext($this->orgA)->get("$a/uyeler")->assertOk();
        $this->actingAs($this->userA)->withContext($this->orgA)->get("$a/site")->assertOk();
    }

    #[Test]
    public function b_organizasyonu_a_kullanicisinin_oturumunda_secilemez(): void
    {
        // Context değiştirme: üyesi olmadığı organizasyon reddedilir; oturum değişmez.
        $this->actingAs($this->userA)->withContext($this->orgA)->from('/panel/organizasyon')->post('/panel/organizasyon', ['organization_id' => $this->orgB->id])->assertRedirect('/panel/organizasyon')->assertSessionHasErrors('organization_id');
        $this->assertSame($this->orgA->id, session(TenantContext::SESSION_KEY));

        // Oturuma doğrudan B yazılsa bile şirket listesi B'yi göstermez (üyelik doğrulanır).
        $response = $this->actingAs($this->userA)->withContext($this->orgB)->get('/panel/sirketler');
        $this->assertContains($response->getStatusCode(), [302, 403, 404, 409]);
        $this->assertStringNotContainsString('B1 A.Ş.', (string) $response->getContent());
    }

    #[Test]
    public function vitrin_siteleri_birbirinin_icerigini_gostermez(): void
    {
        Website::create(['organization_id' => $this->orgA->id, 'name' => 'A Site', 'slug' => 'a-site', 'domain' => 'a.example']);

        $this->get('http://a.example/b-sayfa')->assertNotFound();
        $this->get('http://b.example/b-sayfa')->assertOk()->assertSee('B gizli metin.');
        $this->get('http://localhost/b-sayfa')->assertNotFound();
        $this->get('http://a.example/sitemap.xml')->assertDontSee('b-sayfa');
    }
}
