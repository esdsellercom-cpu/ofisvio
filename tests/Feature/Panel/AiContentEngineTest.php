<?php

namespace Tests\Feature\Panel;

use App\Enums\ContentStatus;
use App\Models\AiJob;
use App\Models\AiPrompt;
use App\Models\AuditLog;
use App\Models\Content;
use App\Models\ContentDraft;
use App\Models\ContentRefreshCandidate;
use App\Models\IntegrationLog;
use App\Models\Service;
use App\Models\Website;
use App\Services\ContentCache;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 60e — AI Content Engine: sağlayıcı kapalıyken taslak adımı durur (diğer adımlar çalışır); bağlıyken
 * Gateway üzerinden taslak (prompt sürümü, token, maliyet kaydı) → doğruluk → SEO → GEO → kopya → inceleme →
 * onay (dört göz) → yayın CMS'e taslak/zamanlanmış içerik; yenileme işi yayındaki metni değil çalışma taslağını
 * üretir; Prompt Registry sürümleme; kullanım & maliyet; içerik yenileme adayı tespiti.
 */
class AiContentEngineTest extends TestCase
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

    private function publish(string $slug, string $title, string $body, ?\DateTimeInterface $publishedAt = null): Content
    {
        $content = Content::create(['website_id' => $this->site->id, 'kind' => 'post', 'slug' => $slug, 'title' => $title, 'excerpt' => 'Elli karakterden uzun bir özet metni; meta açıklama olarak yeterli uzunlukta.', 'body' => $body]);
        $content->forceFill(['status' => ContentStatus::PUBLISHED, 'published_at' => $publishedAt ?? now()->subMinute(), 'published_by' => $this->staff('system_admin')->id])->save();
        app(ContentCache::class)->invalidate($this->site);

        return $content;
    }

    private function enableAi(): void
    {
        config(['integrations.providers.ai.enabled' => true, 'integrations.providers.ai.secrets.api_key' => 'sk-ant-test', 'integrations.providers.ai.price_input_per_mtok' => '3', 'integrations.providers.ai.price_output_per_mtok' => '15']);
    }

    /** @param  array<string, mixed>  $draft */
    private function fakeAi(array $draft, array $claims = []): void
    {
        Http::fake(function (Request $request) use ($draft, $claims) {
            $this->assertStringContainsString('api.anthropic.com/v1/messages', $request->url());
            $this->assertSame('sk-ant-test', $request->header('x-api-key')[0] ?? null);
            $system = (string) $request['system'];
            $isFact = str_contains($system, 'olgu denetçisi');
            $text = json_encode($isFact ? ['claims' => $claims] : $draft, JSON_UNESCAPED_UNICODE);

            return Http::response(['model' => 'claude-sonnet-5', 'content' => [['type' => 'text', 'text' => "```json\n".$text."\n```"]], 'usage' => ['input_tokens' => 1200, 'output_tokens' => 800]]);
        });
    }

    #[Test]
    public function saglayici_kapaliyken_taslak_adimi_durur_ve_hicbir_dis_istek_yapilmaz(): void
    {
        $editor = $this->staff('operations_admin');
        $base = "/panel/seo/{$this->site->id}/ai-icerik";
        Http::fake();

        $this->actingAs($this->staff('finance_admin'))->get('/panel/seo/ai-icerik')->assertForbidden();
        $this->actingAs($editor)->get('/panel/seo/ai-icerik')->assertRedirect($base);
        $this->actingAs($editor)->get($base)->assertOk()->assertSee('AI Content Engine')->assertSee('bağlı değil');
        $this->actingAs($editor)->post($base, ['topic' => 'Konya sanal ofis rehberi', 'keywords' => 'konya sanal ofis', 'intent' => 'local'])->assertRedirect()->assertSessionHasNoErrors();
        $job = AiJob::query()->firstOrFail();
        $this->assertSame('draft', $job->stage);
        $this->actingAs($editor)->from("{$base}/{$job->id}")->post("{$base}/{$job->id}/adim")->assertSessionHasErrors('stage');
        $this->assertSame('draft', $job->fresh()->stage);
        Http::assertNothingSent();
        $this->assertSame(0, IntegrationLog::query()->where('provider', 'ai')->count());
    }

    #[Test]
    public function tam_hat_taslaktan_yayina_dort_goz_ve_maliyet_kaydiyla(): void
    {
        $this->enableAi();
        $editor = $this->staff('operations_admin'); // generate + review
        $admin = $this->staff('system_admin');       // approve + publish
        $service = Service::query()->where('slug', 'sanal-ofis')->firstOrFail();
        $existing = $this->publish('sanal-ofis-nedir', 'Sanal ofis nedir', str_repeat('Sanal ofis tescil adresi sağlar ve posta karşılar. ', 40));
        $body = "## Sanal ofis nedir?\n\nŞirketinizin yasal adresi için Sanal Ofis hizmeti kullanılır; şube 2019 yılından beri hizmet verir ve en iyi seçenektir.\n\n## Nasıl başvurulur?\n\nBaşvuru formu doldurulur, sözleşme imzalanır.\n\n## Sık sorulan sorular\n\n### Tescile uygun mu?\n\nEvet.\n\n### Posta bildirimi var mı?\n\nEvet, aynı gün.\n\n### Süre ne kadar?\n\nBir yıl.";
        $this->fakeAi(['title' => 'Konya sanal ofis rehberi', 'excerpt' => 'Konya sanal ofis: tescil adresi, posta ve toplantı odası ihtiyacı için pratik rehber; kimler için uygun, nasıl başvurulur.', 'body' => $body, 'meta_title' => 'Konya Sanal Ofis Rehberi', 'meta_description' => 'Konya sanal ofis rehberi: tescil adresi, posta ve toplantı odası ihtiyacı için kimler için uygun ve nasıl başvurulur.', 'faq' => [['q' => 'Tescile uygun mu?', 'a' => 'Evet.'], ['q' => 'Posta?', 'a' => 'Aynı gün.'], ['q' => 'Süre?', 'a' => 'Bir yıl.']], 'tags' => ['sanal ofis', 'konya']], [['claim' => 'şube 2019 yılından beri hizmet verir', 'type' => 'tarih', 'risk' => 'high', 'why' => 'kuruluş yılı doğrulanmalı']]);
        $base = "/panel/seo/{$this->site->id}/ai-icerik";

        $this->actingAs($editor)->post($base, ['topic' => 'Konya sanal ofis rehberi', 'keywords' => 'konya sanal ofis', 'services' => [$service->id], 'audience' => 'yeni kurulan şirketler'])->assertRedirect();
        $job = AiJob::query()->firstOrFail();
        $show = "{$base}/{$job->id}";

        // 1) AI taslak: Gateway üzerinden, prompt sürümü + token + maliyet.
        $this->actingAs($editor)->post("{$show}/adim")->assertRedirect()->assertSessionHasNoErrors();
        $job->refresh();
        $this->assertSame('fact_check', $job->stage);
        $this->assertSame('Konya sanal ofis rehberi', $job->draft['title']);
        $this->assertSame(['draft', 1], [$job->prompt_key, $job->prompt_version]);
        $this->assertSame([1200, 800], [$job->input_tokens, $job->output_tokens]);
        $this->assertEqualsWithDelta(1200 / 1e6 * 3 + 800 / 1e6 * 15, (float) $job->cost, 0.0001);
        $this->assertTrue(IntegrationLog::query()->where('provider', 'ai')->where('ok', true)->exists());
        $this->assertFalse(IntegrationLog::query()->where('path', 'like', '%sk-ant%')->exists());
        Http::assertSent(fn (Request $r) => str_contains((string) $r['messages'][0]['content'], 'Sanal Ofis') && str_contains((string) $r['messages'][0]['content'], 'konya sanal ofis'));

        // 2) Doğruluk: kural (yıl, mutlak ifade) + AI iddiası (yüksek risk).
        $this->actingAs($editor)->post("{$show}/adim")->assertRedirect();
        $job->refresh();
        $this->assertSame('seo', $job->stage);
        $types = array_column($job->checks['fact']['claims'], 'type');
        $this->assertContains('yıl', $types);
        $this->assertContains('mutlak ifade', $types);
        $this->assertContains('tarih', $types);
        $this->assertSame(1, $job->checks['fact']['high']);

        // 3) SEO → 4) GEO → 5) kopya (mevcut yazıyla düşük benzerlik) → inceleme.
        $this->actingAs($editor)->post("{$show}/adim")->assertRedirect();
        $this->assertArrayHasKey('score', $job->fresh()->checks['seo']);
        $this->actingAs($editor)->post("{$show}/adim")->assertRedirect();
        $this->assertSame(3, $job->fresh()->checks['geo']['faq_count']);
        $this->assertContains('Sanal Ofis', $job->fresh()->checks['geo']['entities_mentioned']);
        $this->actingAs($editor)->post("{$show}/adim")->assertRedirect()->assertSessionHasNoErrors();
        $job->refresh();
        $this->assertSame('review', $job->stage);
        $this->assertFalse($job->checks['duplicate']['blocked']);

        // Onay inceleme öncesi olamaz; inceleme düzenleme kaydeder; aynı kişi onaylayamaz (dört göz).
        $this->actingAs($admin)->from($show)->post("{$show}/onayla")->assertSessionHasErrors('stage');
        $this->actingAs($editor)->post("{$show}/incele", ['decision' => 'approve_review', 'title' => 'Konya sanal ofis rehberi (2026)', 'note' => 'Yıl ifadesini kontrol ettim'])->assertRedirect()->assertSessionHasNoErrors();
        $job->refresh();
        $this->assertSame('approval', $job->stage);
        $this->assertSame('Konya sanal ofis rehberi (2026)', $job->draft['title']);
        $this->actingAs($editor)->from($show)->post("{$show}/onayla", ['accept_risks' => 1])->assertForbidden(); // operations_admin: approve yok
        $this->actingAs($admin)->from($show)->post("{$show}/onayla")->assertSessionHasErrors('stage'); // yüksek risk kabul edilmedi
        $this->actingAs($admin)->post("{$show}/onayla", ['accept_risks' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('schedule', $job->fresh()->stage);

        // Yayın: zamanlanmış içerik CMS'de (AI doğrudan yayınlamadı).
        $when = now()->addDays(2)->startOfHour();
        $this->actingAs($admin)->post("{$show}/yayinla", ['scheduled_for' => $when->format('Y-m-d\TH:i')])->assertRedirect()->assertSessionHasNoErrors();
        $job->refresh();
        $this->assertSame('done', $job->stage);
        $content = Content::query()->findOrFail($job->content_id);
        $this->assertSame(ContentStatus::SCHEDULED, $content->status);
        $this->assertSame('Konya sanal ofis rehberi (2026)', $content->title);
        $this->assertSame('konya sanal ofis', $content->focus_keyword);
        $this->assertTrue(AuditLog::query()->where('action', 'ai.job_published')->exists());
        $this->assertNotNull($existing->fresh());

        // Kullanım & maliyet + Prompt Registry sürümleme.
        $this->actingAs($editor)->get("{$base}/kullanim")->assertOk()->assertSee('claude-sonnet-5')->assertSee('USD');
        $this->actingAs($editor)->get("{$base}/prompt-kaydi")->assertOk()->assertSee('Makale taslağı')->assertSee('sürüm 1');
        $this->actingAs($editor)->post("{$base}/prompt-kaydi", ['key' => 'draft', 'name' => 'Taslak v2', 'system' => 'Sistem v2', 'template' => 'Konu: {topic} — {brand}'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([false, true], [AiPrompt::query()->where('key', 'draft')->where('version', 1)->value('is_active'), AiPrompt::query()->where('key', 'draft')->where('version', 2)->value('is_active')]);
        $this->assertSame(1, $job->fresh()->prompt_version, 'eski iş eski sürümü referans olarak taşır');
    }

    #[Test]
    public function yenileme_adaylari_tespit_edilir_ve_ai_yenileme_yayindaki_metni_degil_calisma_taslagini_uretir(): void
    {
        $this->enableAi();
        $editor = $this->staff('operations_admin');
        $admin = $this->staff('system_admin');
        $old = $this->publish('eski-rehber', 'Eski rehber', "2022 yılında güncellenen bu rehber. [kırık](/olmayan-yol) bağlantı içerir.\n\n".str_repeat('Gövde metni burada. ', 60), now()->subMonths(18));
        Content::query()->whereKey($old->id)->update(['updated_at' => now()->subMonths(8)]);
        $fresh = $this->publish('yeni-yazi', 'Yeni yazı', str_repeat('Güncel metin. ', 60));
        $base = "/panel/seo/{$this->site->id}/icerik-yenileme";

        $this->actingAs($admin)->get('/panel/seo/icerik-yenileme')->assertRedirect($base);
        $this->actingAs($admin)->post("{$base}/tara")->assertRedirect()->assertSessionHas('status', '1 yenileme adayı bulundu.');
        $candidate = ContentRefreshCandidate::query()->firstOrFail();
        $this->assertSame($old->id, $candidate->content_id);
        $keys = array_column($candidate->reasons, 'key');
        $this->assertContains('stale', $keys);
        $this->assertContains('outdated_year', $keys);
        $this->assertContains('broken_links', $keys);
        $this->assertGreaterThanOrEqual(6, $candidate->score);
        $this->assertNull(ContentRefreshCandidate::query()->where('content_id', $fresh->id)->first());
        $this->actingAs($admin)->get($base)->assertOk()->assertSee('Eski rehber')->assertSee('kırık iç bağlantı')->assertSee('AI ile yenile');

        // AI yenileme işi: kaynak içerik; taslak → ... → yayın = çalışma taslağı; yayındaki metin değişmez.
        $this->fakeAi(['title' => 'Güncel rehber', 'excerpt' => 'Güncellenmiş rehber: tescil adresi, posta bildirimi ve başvuru adımları tek yerde; eski yıl vurguları kaldırıldı.', 'body' => "## Güncel rehber\n\n".str_repeat('Yenilenmiş metin. ', 60)."\n\n## Sık sorulan sorular\n\n### Soru bir?\n\nCevap.\n\n### Soru iki?\n\nCevap.\n\n### Soru üç?\n\nCevap.", 'meta_title' => 'Güncel rehber', 'meta_description' => 'Güncellenmiş rehber: tescil adresi, posta bildirimi ve başvuru adımları tek yerde; eski yıl vurguları kaldırıldı.', 'faq' => [['q' => 'a?', 'a' => 'b'], ['q' => 'c?', 'a' => 'd'], ['q' => 'e?', 'a' => 'f']], 'change_summary' => ['Eski yıl ifadeleri kaldırıldı']]);
        $aiBase = "/panel/seo/{$this->site->id}/ai-icerik";
        $this->actingAs($editor)->post($aiBase, ['topic' => 'Eski rehber yenileme', 'source_content_id' => $old->id, 'keywords' => 'sanal ofis rehberi'])->assertRedirect();
        $job = AiJob::query()->firstOrFail();
        $this->assertSame('refresh', $job->kind);
        $this->assertSame('planned', $candidate->fresh()->status);
        $this->assertSame($job->id, $candidate->fresh()->ai_job_id);
        $show = "{$aiBase}/{$job->id}";

        foreach (['draft', 'fact_check', 'seo', 'geo', 'duplicate'] as $stage) {
            $this->assertSame($stage, $job->fresh()->stage);
            $this->actingAs($editor)->post("{$show}/adim")->assertRedirect()->assertSessionHasNoErrors();
        }

        Http::assertSent(fn (Request $r) => str_contains((string) $r['messages'][0]['content'], 'Gövde metni burada'));
        $this->assertSame('review', $job->fresh()->stage);
        $this->actingAs($editor)->post("{$show}/incele", ['decision' => 'approve_review'])->assertRedirect();
        $this->actingAs($admin)->post("{$show}/onayla", ['accept_risks' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("{$show}/yayinla")->assertRedirect()->assertSessionHasNoErrors();

        $job->refresh();
        $this->assertSame('done', $job->stage);
        $this->assertSame($old->id, $job->content_id);
        $this->assertSame('Eski rehber', $old->fresh()->title, 'yayındaki başlık değişmedi');
        $this->assertStringContainsString('2022 yılında', (string) $old->fresh()->body, 'yayındaki gövde değişmedi');
        $draft = ContentDraft::query()->where('content_id', $old->id)->firstOrFail();
        $this->assertSame('Güncel rehber', $draft->title);
        $this->assertStringContainsString('Yenilenmiş metin', (string) $draft->body);
        $this->assertSame('done', $candidate->fresh()->status);

        // Karar: yok say / yeniden aç (seo.edit).
        $this->actingAs($editor)->post("{$base}/{$candidate->id}", ['status' => 'ignored'])->assertRedirect();
        $this->assertSame('ignored', $candidate->fresh()->status);
        $this->actingAs($this->staff('finance_admin'))->post("{$base}/{$candidate->id}", ['status' => 'open'])->assertForbidden();
    }

    #[Test]
    public function kopya_taslak_taslak_asamasina_geri_doner(): void
    {
        $this->enableAi();
        $editor = $this->staff('operations_admin');
        $body = str_repeat('Sanal ofis tescil adresi sağlar ve gelen postayı aynı gün bildirir. ', 40);
        $this->publish('mevcut', 'Mevcut yazı', $body);
        $this->fakeAi(['title' => 'Kopya', 'excerpt' => str_repeat('özet ', 30), 'body' => $body, 'meta_title' => 'Kopya', 'meta_description' => str_repeat('açıklama ', 15), 'faq' => []]);
        $aiBase = "/panel/seo/{$this->site->id}/ai-icerik";
        $this->actingAs($editor)->post($aiBase, ['topic' => 'Kopya deneme'])->assertRedirect();
        $job = AiJob::query()->firstOrFail();
        $show = "{$aiBase}/{$job->id}";

        foreach (['draft', 'fact_check', 'seo', 'geo'] as $stage) {
            $this->actingAs($editor)->post("{$show}/adim")->assertRedirect()->assertSessionHasNoErrors();
        }

        $this->actingAs($editor)->from($show)->post("{$show}/adim")->assertSessionHasErrors('stage');
        $job->refresh();
        $this->assertSame('draft', $job->stage);
        $this->assertTrue($job->checks['duplicate']['blocked']);
        $this->assertSame('Mevcut yazı', $job->checks['duplicate']['title']);
    }
}
