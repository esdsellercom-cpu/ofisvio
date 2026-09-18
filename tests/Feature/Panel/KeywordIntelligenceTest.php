<?php

namespace Tests\Feature\Panel;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\SeoKeyword;
use App\Models\Service;
use App\Models\Website;
use App\Services\ContentCache;
use App\Services\KeywordService;
use App\Services\LinkGraphService;
use App\Services\SeoSettingsService;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 60c — Keyword Intelligence (eşleme, kanibalizasyon, boşluk, sayfa üstü kontrol, kümeler, örtük odak
 * kelimeleri) ve Internal Linking Engine (graf, yetim/kırık, matris, öneriler, manuel kural = ayar satırı).
 */
class KeywordIntelligenceTest extends TestCase
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

    /** @param  array<string, mixed>  $extra */
    private function publish(string $kind, string $slug, string $title, string $body, array $extra = []): Content
    {
        $content = Content::create(array_merge(['website_id' => $this->site->id, 'kind' => $kind, 'slug' => $slug, 'title' => $title, 'excerpt' => 'Elli karakterden uzun bir özet metni; meta açıklama olarak yeterli uzunlukta.', 'body' => $body], $extra));
        $content->forceFill(['status' => ContentStatus::PUBLISHED, 'published_at' => now()->subMinute(), 'show_in_nav' => (bool) ($extra['show_in_nav'] ?? true)])->save();
        app(ContentCache::class)->invalidate($this->site);

        return $content;
    }

    #[Test]
    public function anahtar_kelime_eslemesi_kanibalizasyon_bosluk_ve_sayfa_ustu_kontrol(): void
    {
        $admin = $this->staff('system_admin');
        $finance = $this->staff('finance_admin');
        $service = Service::query()->where('slug', 'sanal-ofis')->firstOrFail();
        $post = $this->publish('post', 'sanal-ofis-fiyatlari', 'Sanal ofis fiyatları 2026', str_repeat('Sanal ofis fiyatları neye göre değişir? ', 30), ['focus_keyword' => 'sanal ofis fiyatları', 'category' => 'Sanal Ofis']);
        $base = "/panel/seo/{$this->site->id}/anahtar-kelimeler";

        $this->actingAs($finance)->get('/panel/seo/anahtar-kelimeler')->assertForbidden();
        $this->actingAs($admin)->get('/panel/seo/anahtar-kelimeler')->assertRedirect($base);
        $this->actingAs($admin)->get($base)->assertOk()->assertSee('Keyword Intelligence')->assertSee('sanal ofis fiyatları')->assertSee('örtük');

        // Birincil kelime → hizmet; aynı kelime → yazı = kanibalizasyon; hedefsiz kelime = boşluk.
        $this->actingAs($admin)->post($base, ['keyword' => 'Sanal Ofis', 'role' => 'primary', 'intent' => 'commercial', 'cluster' => 'Sanal Ofis', 'target' => 'service:'.$service->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->post($base, ['keyword' => 'sanal  ofis', 'role' => 'primary', 'intent' => 'informational', 'target' => 'content:'.$post->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->post($base, ['keyword' => 'konya hazır ofis kiralama', 'role' => 'primary', 'intent' => 'local', 'cluster' => 'hazir-ofis'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->from($base)->post($base, ['keyword' => 'x', 'role' => 'primary', 'intent' => 'local'])->assertSessionHasErrors('keyword');
        $this->actingAs($admin)->from($base)->post($base, ['keyword' => 'ölü hedef', 'role' => 'primary', 'intent' => 'local', 'target' => 'service:999999'])->assertSessionHasErrors('keyword');
        $this->actingAs($admin)->from($base)->post($base, ['keyword' => 'sanal ofis', 'role' => 'primary', 'intent' => 'local', 'target' => 'service:'.$service->id])->assertSessionHasErrors('keyword'); // aynı hedefe ikinci kez
        $this->assertSame(3, SeoKeyword::count());
        $this->assertSame('sanal ofis', SeoKeyword::query()->where('target_type', 'content')->value('normalized'));
        $this->assertSame('sanal-ofis', SeoKeyword::query()->where('target_type', 'service')->value('cluster'));

        $page = $this->actingAs($admin)->get($base)->assertOk();
        $page->assertSee('Kanibalizasyon')->assertSee('sanal ofis</strong>', false)->assertSee($service->path())->assertSee($post->path());
        $page->assertSee('konya hazır ofis kiralama')->assertSee('hedef yok');
        $page->assertSee('sanal-ofis</strong>', false)->assertSee('varlığa bağlı değil');

        // Sayfa üstü: "sanal ofis" hizmetin adında ve özetinde geçer; yazı başlığında da.
        $analysis = app(KeywordService::class)->analysis($this->site);
        $serviceRow = collect($analysis['rows'])->first(fn (array $r) => $r['model']->target_type === 'service');
        $this->assertTrue($serviceRow['on_page']['title']);
        $this->assertSame(1, count($analysis['cannibalization']));
        $this->assertSame(['sanal ofis'], array_column($analysis['cannibalization'], 'keyword'));
        $this->assertCount(1, $analysis['gaps']);
        $this->assertSame($post->path(), $analysis['implicit'][0]['content']->path());

        // Silme.
        $gap = SeoKeyword::query()->whereNull('target_path')->firstOrFail();
        $this->actingAs($admin)->delete("{$base}/{$gap->id}")->assertRedirect();
        $this->assertNull(SeoKeyword::find($gap->id));
        $this->actingAs($finance)->delete("{$base}/".SeoKeyword::query()->value('id'))->assertForbidden();
    }

    #[Test]
    public function ic_baglanti_grafi_yetim_kirik_matris_oneri_ve_manuel_kural(): void
    {
        $admin = $this->staff('system_admin');
        $service = Service::query()->where('slug', 'sanal-ofis')->firstOrFail();
        $linked = $this->publish('post', 'adres-rehberi', 'Yasal adres rehberi', 'Tescil için [sanal ofis]('.$service->path().') seçin. Ayrıca [kırık](/olmayan-sayfa) bir bağlantı. '.str_repeat('Dolgu metni. ', 40), ['tags' => ['adres'], 'category' => 'Rehber']);
        $orphan = $this->publish('page', 'gizli-kampanya', 'Gizli kampanya', 'Bu sayfaya hiçbir yerden bağlantı yok. Sanal Ofis kelimesi burada geçiyor ama bağlı değil. '.str_repeat('Dolgu. ', 30), ['tags' => ['adres'], 'category' => 'Rehber', 'show_in_nav' => false]);
        $base = "/panel/seo/{$this->site->id}/ic-baglantilar";

        $this->actingAs($admin)->get('/panel/seo/ic-baglantilar')->assertRedirect($base);
        $page = $this->actingAs($admin)->get($base)->assertOk();
        $page->assertSee('Internal Linking Engine')->assertSee('Gizli kampanya')->assertSee('yetim')->assertSee('Hizmet ↔ Lokasyon ↔ Blog matrisi');

        $graph = app(LinkGraphService::class)->graph($this->site);
        $this->assertContains($orphan->path(), array_column($graph['orphans'], 'path'));
        $this->assertNotContains($linked->path(), array_column($graph['orphans'], 'path'), 'blog listesinden bağlantı alır');
        $this->assertSame(1, $graph['nodes'][$linked->path()]['broken']);
        $this->assertArrayHasKey($linked->path().'|'.$service->path(), $graph['edges']);
        $this->assertGreaterThan(0, $graph['matrix']['post']['service']);
        $this->assertGreaterThan(0, $graph['matrix']['service']['location']);

        // Öneri: yetim sayfa metninde "Sanal Ofis" geçiyor ama bağlı değil → hizmet önerisi (4) + etiket/kategori ile yazı önerisi.
        $toService = collect($graph['suggestions'])->first(fn (array $s) => $s['from']['path'] === $orphan->path() && $s['to']['path'] === $service->path());
        $this->assertNotNull($toService);
        $this->assertStringContainsString('metinde geçiyor', implode(' ', $toService['reasons']));
        $this->assertSame('Sanal Ofis', $toService['anchor']);
        $toPost = collect($graph['suggestions'])->first(fn (array $s) => $s['from']['path'] === $orphan->path() && $s['to']['path'] === $linked->path());
        $this->assertNotNull($toPost);
        $this->assertSame(4, $toPost['score']); // ortak etiket 2 + aynı kategori 2

        // Manuel kural = links.keywords ayar satırı; içerikte otomatik bağlantı kenarı doğar; çift kural reddedilir.
        $this->actingAs($admin)->post("{$base}/kural", ['keyword' => 'Sanal Ofis', 'url' => $service->path()])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->from($base)->post("{$base}/kural", ['keyword' => 'sanal ofis', 'url' => $service->path()])->assertSessionHasErrors('keyword');
        $this->actingAs($admin)->from($base)->post("{$base}/kural", ['keyword' => 'abc', 'url' => 'javascript:alert(1)'])->assertSessionHasErrors('keyword');
        $rules = app(SeoSettingsService::class)->get($this->site->fresh(), 'links.keywords');
        $this->assertSame([['keyword' => 'Sanal Ofis', 'url' => $service->path()]], $rules);
        // Kural kenarı yalnız otomatik iç bağlantı açıkken (varsayılan kapalı).
        $this->assertArrayNotHasKey($orphan->path().'|'.$service->path(), app(LinkGraphService::class)->graph($this->site->fresh())['edges']);
        app(SeoSettingsService::class)->set($admin, $this->site->fresh(), ['links.auto_enabled' => true], 'test');
        $graph = app(LinkGraphService::class)->graph($this->site->fresh());
        $this->assertSame('otomatik kural', $graph['edges'][$orphan->path().'|'.$service->path()]['source'] ?? null);
        $this->actingAs($admin)->delete("{$base}/kural", ['keyword' => 'Sanal Ofis'])->assertRedirect();
        $this->assertSame([], app(SeoSettingsService::class)->get($this->site->fresh(), 'links.keywords'));

        // Menü.
        $this->actingAs($admin)->get('/panel/operasyon')->assertOk()->assertSee('Keyword Intelligence')->assertSee('Internal Linking')->assertSee('Programatik SEO');
    }
}
