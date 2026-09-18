<?php

namespace Tests\Feature\Panel;

use App\Enums\ContentStatus;
use App\Models\AuditLog;
use App\Models\Content;
use App\Models\SeoIssue;
use App\Models\Service;
use App\Models\User;
use App\Models\Website;
use App\Seo\HealthCenter;
use App\Seo\SchemaValidator;
use App\Services\ContentCache;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 60 — SEO & GEO Command Center: hizmet/etkinlik sayfalarının kendi head'i (önceden ana sayfa canonical'ı
 * basılıyordu), sağlık merkezi bulguları gerçek veriden, karar (yok say/çözüldü) kalıcı, otomatik düzeltme
 * onaylı ve yetki kapısına bağlı (edit vs critical+JIT), Schema Manager gerçek JSON-LD + doğrulama.
 */
class SeoCommandCenterTest extends TestCase
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

    /** @param  array<string, mixed>  $values */
    private function settings(array $values): void
    {
        $stored = (array) ($this->site->fresh()->seo_settings ?? []);
        $this->site->forceFill(['seo_settings' => array_replace($stored, $values)])->save();
        $this->site = $this->site->fresh();
        app(ContentCache::class)->invalidate($this->site);
    }

    private function jit(User $user): void
    {
        $this->actingAs($user)->post("/panel/seo/{$this->site->id}/jit/settings", ['reason' => 'command center kritik düzeltme testi', 'ttl_minutes' => 30, 'sekme' => 'url'])->assertRedirect();
    }

    #[Test]
    public function hizmet_ve_etkinlik_sayfalari_kendi_baslik_canonical_ve_semasini_tasir(): void
    {
        $service = Service::query()->where('is_active', true)->firstOrFail();
        $page = $this->get('http://localhost'.$service->path())->assertOk()->getContent();

        $this->assertStringContainsString('<title>'.$service->name.' — Ofisvio</title>', $page);
        $this->assertStringContainsString('<link rel="canonical" href="'.$this->site->baseUrl().$service->path().'">', $page);
        $this->assertStringNotContainsString('<link rel="canonical" href="'.$this->site->baseUrl().'/">', $page);
        $this->assertStringContainsString('"@type":"Service"', $page);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $page);
        $this->assertStringContainsString('"name":"Çözümler"', $page);

        $list = $this->get('http://localhost/cozumler')->assertOk()->getContent();
        $this->assertStringContainsString('<title>Çözümler — Ofisvio</title>', $list);
        $this->assertStringContainsString('<link rel="canonical" href="'.$this->site->baseUrl().'/cozumler">', $list);

        $events = $this->get('http://localhost/etkinlikler')->assertOk()->getContent();
        $this->assertStringContainsString('<link rel="canonical" href="'.$this->site->baseUrl().'/etkinlikler">', $events);

        // Schema Manager aynı üreticiyi kullanır: hizmet sayfası incelemesi Service düğümünü ve doğrulamayı gösterir.
        $admin = $this->staff('system_admin');
        $this->actingAs($admin)->get("/panel/seo/{$this->site->id}/sema?yol=".$service->path())->assertOk()
            ->assertSee('Schema Manager')->assertSee('&quot;@type&quot;: &quot;Service&quot;', false)->assertSee($service->name)->assertDontSee('Bu yol için yayında sayfa bulunamadı');
        $this->actingAs($admin)->get("/panel/seo/{$this->site->id}/sema?yol=/olmayan-sayfa")->assertOk()->assertSee('Bu yol için yayında sayfa bulunamadı');
        $this->actingAs($admin)->get('/panel/seo/sema')->assertRedirect("/panel/seo/{$this->site->id}/sema");
    }

    #[Test]
    public function sema_dogrulayici_zorunlu_alan_ve_bicim_hatalarini_bulur(): void
    {
        $v = new SchemaValidator;
        $ok = $v->validate(['@context' => 'https://schema.org', '@graph' => [
            ['@type' => 'Organization', 'name' => 'Ofisvio', 'url' => 'https://ofisvio.com', 'logo' => 'https://ofisvio.com/logo.png', 'sameAs' => ['https://x.com/ofisvio'], 'telephone' => '0', 'email' => 'a@b.c', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Konya', 'addressCountry' => 'TR', 'streetAddress' => 'x', 'postalCode' => '42000']],
            ['@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => 'A'], ['@type' => 'ListItem', 'position' => 2, 'name' => 'B']]],
        ]]);
        $this->assertSame([], $ok['errors'], implode(' | ', $ok['errors']));
        $this->assertSame([], $ok['warnings']);

        $bad = $v->validate(['@context' => 'https://schema.org', '@graph' => [
            ['@type' => 'Article', 'headline' => 'x', 'datePublished' => 'dün', 'url' => 'blog/x'],
            ['@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 2, 'name' => 'A']]],
            ['@type' => 'FAQPage', 'mainEntity' => [['@type' => 'Question', 'name' => 'S?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => '']]]],
            ['name' => 'türsüz'],
            ['@type' => 'Product', 'name' => 'x', 'aggregateRating' => ['ratingValue' => 5]],
        ]]);
        $errors = implode("\n", $bad['errors']);
        $this->assertStringContainsString('Article: zorunlu alan "author" eksik.', $errors);
        $this->assertStringContainsString('"datePublished" ISO 8601', $errors);
        $this->assertStringContainsString('"url" mutlak adres olmalı', $errors);
        $this->assertStringContainsString('position sırası bozuk', $errors);
        $this->assertStringContainsString('acceptedAnswer.text', $errors);
        $this->assertStringContainsString('@type eksik', $errors);
        $this->assertStringContainsString('aggregateRating', implode("\n", $bad['warnings']));
        $this->assertSame(['warnings' => ['Sayfada JSON-LD yok.']], array_intersect_key($v->validate([]), ['warnings' => 1]));
    }

    #[Test]
    public function command_center_bulgular_gercek_veriden_karar_kalici_ve_duzeltme_yetki_kapili(): void
    {
        $admin = $this->staff('system_admin');
        $ops = $this->staff('operations_admin'); // seo.edit var, seo.settings yok
        $finance = $this->staff('finance_admin'); // seo.view yok

        // Zayıf içerik + şema kapalı + canonical otomatik kapalı → bulgular.
        $thin = Content::create(['website_id' => $this->site->id, 'kind' => 'page', 'slug' => 'kisa-sayfa', 'title' => 'Kısa sayfa', 'excerpt' => 'Elli karakterden uzun bir özet metni; meta açıklama olarak yeterli uzunluktadır.', 'body' => 'Çok kısa.', 'author_id' => $admin->id]);
        $thin->forceFill(['status' => ContentStatus::PUBLISHED, 'published_at' => now()->subDay()])->save();
        $this->settings(['schema.enabled' => false, 'url.canonical_auto' => false]);

        $this->actingAs($finance)->get('/panel/seo/merkez')->assertForbidden();
        $this->actingAs($admin)->get('/panel/seo/merkez')->assertRedirect("/panel/seo/{$this->site->id}/merkez");
        $page = $this->actingAs($admin)->get("/panel/seo/{$this->site->id}/merkez")->assertOk()
            ->assertSee('Command Center')->assertSee('Sağlık puanı')->assertSee('JSON-LD üretimi kapalı')->assertSee('Otomatik canonical kapalı')
            ->assertSee('Kısa sayfa: Gövde 300 karakterden kısa.')->assertSee('Search Console bağlı değil')->assertSee('Sayfayı noindex yap');

        foreach (HealthCenter::CATEGORIES as $label) {
            $page->assertSee($label);
        }

        $report = app(HealthCenter::class)->report($this->site);
        $schemaIssue = collect($report['issues'])->firstWhere('fix', 'schema_enable');
        $canonicalIssue = collect($report['issues'])->firstWhere('fix', 'canonical_auto');
        $thinIssue = collect($report['issues'])->firstWhere('fix', 'noindex_content:'.$thin->id);
        $this->assertNotNull($schemaIssue);
        $this->assertNotNull($canonicalIssue);
        $this->assertNotNull($thinIssue);
        $this->assertSame('thin_content', $thinIssue['category']);
        $this->assertSame('critical', $canonicalIssue['fix_mode']);

        // Karar: yok say → açık listeden düşer, "yok sayılan" süzgecinde görünür; kalıcı ve audit'li.
        $this->actingAs($ops)->post("/panel/seo/{$this->site->id}/merkez/karar", ['key' => $thinIssue['key'], 'status' => 'ignored', 'note' => 'Bilinçli kısa sayfa'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('ignored', SeoIssue::query()->where('issue_key', $thinIssue['key'])->value('status'));
        $this->actingAs($admin)->get("/panel/seo/{$this->site->id}/merkez")->assertOk()->assertDontSee('Kısa sayfa: Gövde 300 karakterden kısa.');
        $this->actingAs($admin)->get("/panel/seo/{$this->site->id}/merkez?durum=ignored")->assertOk()->assertSee('Kısa sayfa: Gövde 300 karakterden kısa.')->assertSee('Bilinçli kısa sayfa');
        $this->assertTrue(AuditLog::query()->where('action', 'seo.issue_decided')->exists());

        // Onay kutusu olmadan düzeltme uygulanmaz.
        $this->actingAs($ops)->from("/panel/seo/{$this->site->id}/merkez")->post("/panel/seo/{$this->site->id}/merkez/duzelt", ['key' => $schemaIssue['key']])->assertSessionHasErrors('confirm');
        $this->assertFalse((bool) ($this->site->fresh()->seo_settings['schema.enabled'] ?? true));

        // edit modlu düzeltme: seo.edit yeter; finance 403.
        $this->actingAs($finance)->post("/panel/seo/{$this->site->id}/merkez/duzelt", ['key' => $schemaIssue['key'], 'confirm' => 1])->assertForbidden();
        $this->actingAs($ops)->post("/panel/seo/{$this->site->id}/merkez/duzelt", ['key' => $schemaIssue['key'], 'confirm' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue((bool) $this->site->fresh()->seo_settings['schema.enabled']);
        $this->assertSame('resolved', SeoIssue::query()->where('issue_key', $schemaIssue['key'])->value('status'));
        $this->assertTrue(AuditLog::query()->where('action', 'seo.autofix_applied')->exists());

        // critical modlu düzeltme edit kapısından geçmez; kritik kapı JIT ister; JIT sonrası uygulanır.
        $this->actingAs($ops)->from("/panel/seo/{$this->site->id}/merkez")->post("/panel/seo/{$this->site->id}/merkez/duzelt", ['key' => $canonicalIssue['key'], 'confirm' => 1])->assertSessionHasErrors('issue');
        $this->assertFalse((bool) $this->site->fresh()->seo_settings['url.canonical_auto']);
        $this->actingAs($admin)->post("/panel/seo/{$this->site->id}/merkez/duzelt-kritik", ['key' => $canonicalIssue['key'], 'confirm' => 1])->assertForbidden();
        $this->jit($admin);
        $this->actingAs($admin)->post("/panel/seo/{$this->site->id}/merkez/duzelt-kritik", ['key' => $canonicalIssue['key'], 'confirm' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue((bool) $this->site->fresh()->seo_settings['url.canonical_auto']);

        // Düzeltme sonrası bulgu üretilmez; "çözüldü" sayısı artar.
        $after = app(HealthCenter::class)->report($this->site->fresh());
        $this->assertNull(collect($after['issues'])->firstWhere('fix', 'schema_enable'));
        $this->assertNull(collect($after['issues'])->firstWhere('fix', 'canonical_auto'));
        $this->assertGreaterThanOrEqual(2, $after['summary']['resolved']);

        // Menü: yeni gruplar.
        $this->actingAs($admin)->get('/panel/operasyon')->assertOk()->assertSee('Command Center')->assertSee('Schema Manager')->assertSee('Performans paneli');
    }
}
