<?php

namespace Tests\Feature\Panel;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\Website;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 9 — CMS Core: taslak -> inceleme -> (onay) -> yayın akışı, izinler,
 * revizyonlar, vitrin görünürlüğü, zamanlanmış yayın.
 *
 * Roller (matris): system_admin her şeyi yapar; operations_admin content.edit/
 * review/schedule taşır ama create/approve/publish taşımaz.
 */
class ContentFlowTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(WebsiteSeeder::class);
        $this->website = Website::query()->default()->firstOrFail();
    }

    private function draft(array $overrides = []): Content
    {
        return Content::create(array_merge([
            'website_id' => $this->website->id,
            'kind' => 'post',
            'slug' => 'ornek-yazi',
            'title' => 'Örnek yazı',
            'excerpt' => 'Özet.',
            'body' => "## Başlık\n\nGövde metni <script>alert(1)</script> [link](javascript:alert(1))",
            'category' => 'Sanal Ofis',
        ], $overrides));
    }

    #[Test]
    public function seeder_taslak_iskeleti_kurar_vitrinde_yazi_gostermez(): void
    {
        $this->assertSame(3, Content::where('kind', 'post')->where('status', 'DRAFT')->count());
        $this->assertSame(3, Content::where('kind', 'page')->where('requires_approval', true)->count());

        // Taslak vitrine sızmaz; yazı yoksa bölüm basılmaz.
        $this->get('/')->assertOk()->assertDontSee('Çalışma kültürü günlüğü');
        $this->get('/blog')->assertOk()->assertSee('Henüz yayınlanmış yazı yok');
        $this->get('/blog/'.Content::where('kind', 'post')->first()->slug)->assertNotFound();
        $this->get('/aydinlatma-metni')->assertNotFound();
    }

    #[Test]
    public function editor_yazar_inceleme_yayin_ve_vitrin(): void
    {
        $admin = $this->staff('system_admin');

        $this->actingAs($admin)->get('/panel/icerik')->assertOk()->assertSee('Yeni yazı');

        $this->actingAs($admin)->post('/panel/icerik', [
            'website_id' => $this->website->id,
            'kind' => 'post',
            'title' => 'Sanal ofis ile şirket kurmak',
            'excerpt' => 'Kısa özet.',
            'body' => "## Adımlar\n\n- Belge\n- Sözleşme\n\n<script>alert(1)</script> [kötü](javascript:alert(1))",
            'category' => 'Sanal Ofis',
        ])->assertRedirect();

        $content = Content::where('title', 'Sanal ofis ile şirket kurmak')->firstOrFail();
        $this->assertSame('sanal-ofis-ile-sirket-kurmak', $content->slug);
        $this->assertSame(ContentStatus::DRAFT, $content->status);
        $this->assertSame(1, $content->reading_minutes);
        $this->assertSame(1, ContentRevision::where('content_id', $content->id)->count());

        // Taslak vitrinde yok.
        $this->get('/blog/'.$content->slug)->assertNotFound();

        // Doğrudan yayın: state machine reddeder (DRAFT -> PUBLISHED yok).
        $this->actingAs($admin)->from("/panel/icerik/{$content->id}")
            ->post("/panel/icerik/{$content->id}/yayinla")
            ->assertSessionHasErrors('status');

        // Düzenleme yeni revizyon bırakır.
        $this->actingAs($admin)->put("/panel/icerik/{$content->id}", [
            'title' => 'Sanal ofis ile şirket kurmak',
            'body' => "## Adımlar\n\nGüncel gövde.",
        ])->assertRedirect("/panel/icerik/{$content->id}");
        $this->assertSame(2, ContentRevision::where('content_id', $content->id)->count());

        // İncelemeye gönder -> yayınla (onay gerekmeyen içerik).
        $this->actingAs($admin)->post("/panel/icerik/{$content->id}/incelemeye-gonder")->assertRedirect();
        $this->assertSame(ContentStatus::IN_REVIEW, $content->fresh()->status);

        // İncelemede düzenlenemez.
        $this->actingAs($admin)->get("/panel/icerik/{$content->id}/duzenle")
            ->assertRedirect("/panel/icerik/{$content->id}")
            ->assertSessionHasErrors('status');

        $this->actingAs($admin)->post("/panel/icerik/{$content->id}/yayinla")->assertRedirect();
        $content->refresh();
        $this->assertSame(ContentStatus::PUBLISHED, $content->status);
        $this->assertNotNull($content->published_at);
        $this->assertSame($admin->id, (int) $content->published_by);

        // Vitrin: ana sayfa kartı, liste, detay; markdown güvenli render.
        $this->get('/')->assertOk()->assertSee('Güncel İçerikler')->assertSee('Sanal ofis ile şirket kurmak');
        $this->get('/blog')->assertOk()->assertSee('Sanal ofis ile şirket kurmak');
        $this->get('/blog/'.$content->slug)
            ->assertOk()
            ->assertSee('<h2>Adımlar</h2>', false)
            ->assertSee('Güncel gövde.');

        // Yayından kaldır: taslağa döner, vitrinden kalkar.
        $this->actingAs($admin)->post("/panel/icerik/{$content->id}/yayindan-kaldir")->assertRedirect();
        $this->assertSame(ContentStatus::DRAFT, $content->fresh()->status);
        $this->get('/blog/'.$content->slug)->assertNotFound();
    }

    #[Test]
    public function markdown_render_html_ve_zararli_baglantiyi_suzer(): void
    {
        $content = $this->draft();

        $html = $content->renderedBody();

        $this->assertStringContainsString('<h2>Başlık</h2>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    #[Test]
    public function onay_gerektiren_icerik_onaysiz_yayinlanamaz(): void
    {
        $admin = $this->staff('system_admin');
        $page = Content::where('slug', 'aydinlatma-metni')->firstOrFail(); // requires_approval

        // Gövdesiz yayın da olmaz.
        $this->actingAs($admin)->put("/panel/icerik/{$page->id}", ['title' => 'Aydınlatma Metni', 'body' => '# KVKK']);
        $this->actingAs($admin)->post("/panel/icerik/{$page->id}/incelemeye-gonder");

        $this->actingAs($admin)->from("/panel/icerik/{$page->id}")
            ->post("/panel/icerik/{$page->id}/yayinla")
            ->assertSessionHasErrors('status');
        $this->assertSame(ContentStatus::IN_REVIEW, $page->fresh()->status);

        $this->actingAs($admin)->post("/panel/icerik/{$page->id}/onayla", ['note' => 'Hukuk onayı ref#12'])->assertRedirect();
        $this->assertSame(ContentStatus::APPROVED, $page->fresh()->status);
        $this->assertSame($admin->id, (int) $page->fresh()->approved_by);

        $this->actingAs($admin)->post("/panel/icerik/{$page->id}/yayinla")->assertRedirect();
        $this->assertSame(ContentStatus::PUBLISHED, $page->fresh()->status);

        // Sayfa vitrinde ve footer'da.
        $this->get('/aydinlatma-metni')->assertOk()->assertSee('KVKK');
        $this->get('/')->assertSee('href="'.route('site.page', 'aydinlatma-metni').'"', false);
    }

    #[Test]
    public function izinler_matrise_gore_uygulanir(): void
    {
        // operations_admin: content.edit / review / schedule VAR; create / approve / publish YOK.
        $ops = $this->staff('operations_admin');
        $content = $this->draft();

        $this->actingAs($ops)->get('/panel/icerik')->assertOk();
        $this->actingAs($ops)->post('/panel/icerik', ['website_id' => $this->website->id, 'kind' => 'post', 'title' => 'Yetkisiz'])->assertForbidden();
        $this->actingAs($ops)->post("/panel/icerik/{$content->id}/incelemeye-gonder")->assertRedirect();
        $this->actingAs($ops)->post("/panel/icerik/{$content->id}/onayla")->assertForbidden();
        $this->actingAs($ops)->post("/panel/icerik/{$content->id}/yayinla")->assertForbidden();

        // Geri gönderme gerekçe ister (content.review var).
        $this->actingAs($ops)->from("/panel/icerik/{$content->id}")
            ->post("/panel/icerik/{$content->id}/geri-gonder", ['note' => ''])
            ->assertSessionHasErrors('note');
        $this->actingAs($ops)->post("/panel/icerik/{$content->id}/geri-gonder", ['note' => 'Kaynak ekleyin.'])->assertRedirect();
        $this->assertSame(ContentStatus::DRAFT, $content->fresh()->status);
        $this->assertSame('Kaynak ekleyin.', $content->fresh()->review_note);

        // Müşteri kullanıcısı CMS'e giremez (owner'ın content.edit'i company kapsamlı; global değil).
        $acme = $this->organization('Acme');
        $owner = $this->owner($acme);
        $this->actingAs($owner)->get('/panel/icerik')->assertForbidden();
    }

    #[Test]
    public function zamanlanmis_icerik_zamani_gelince_komutla_yayinlanir(): void
    {
        $admin = $this->staff('system_admin');
        $content = $this->draft();
        $this->actingAs($admin)->post("/panel/icerik/{$content->id}/incelemeye-gonder");

        // Geçmiş tarih reddedilir.
        $this->actingAs($admin)->from("/panel/icerik/{$content->id}")
            ->post("/panel/icerik/{$content->id}/zamanla", ['scheduled_for' => now()->subHour()->format('Y-m-d\TH:i')])
            ->assertSessionHasErrors('scheduled_for');

        $at = now()->addHour()->startOfMinute();
        $this->actingAs($admin)->post("/panel/icerik/{$content->id}/zamanla", ['scheduled_for' => $at->format('Y-m-d\TH:i')])->assertRedirect();
        $this->assertSame(ContentStatus::SCHEDULED, $content->fresh()->status);
        $this->get('/blog/'.$content->slug)->assertNotFound();

        // Henüz değil.
        $this->artisan('content:publish-scheduled')->expectsOutputToContain('Zamanı gelen içerik yok')->assertSuccessful();
        $this->assertSame(ContentStatus::SCHEDULED, $content->fresh()->status);

        // Zaman geldi.
        Carbon::setTestNow($at->copy()->addMinute());
        $this->artisan('content:publish-scheduled')->expectsOutputToContain('1 içerik yayınlandı')->assertSuccessful();
        $content->refresh();
        $this->assertSame(ContentStatus::PUBLISHED, $content->status);
        $this->assertTrue($content->published_at->equalTo($at));
        $this->get('/blog/'.$content->slug)->assertOk();
        Carbon::setTestNow();
    }

    #[Test]
    public function slug_ayni_turde_tekildir_ve_route_catismaz(): void
    {
        $admin = $this->staff('system_admin');

        $this->actingAs($admin)->post('/panel/icerik', ['website_id' => $this->website->id, 'kind' => 'page', 'title' => 'Panel', 'body' => 'x']);
        $page = Content::where('kind', 'page')->where('slug', 'panel')->firstOrFail();
        $this->actingAs($admin)->post("/panel/icerik/{$page->id}/incelemeye-gonder");
        $this->actingAs($admin)->post("/panel/icerik/{$page->id}/yayinla");

        // "panel" slug'lı yayındaki sayfa /panel route'unu GÖLGELEMEZ: panel
        // (context yok -> seçim ekranı) yanıt verir, CMS sayfası değil.
        $this->actingAs($admin)->get('/panel')->assertRedirect('/panel/organizasyon');

        // Aynı türde aynı başlık: slug -2 alır; farklı türde aynı slug serbest.
        $this->actingAs($admin)->post('/panel/icerik', ['website_id' => $this->website->id, 'kind' => 'page', 'title' => 'Panel']);
        $this->assertTrue(Content::where('kind', 'page')->where('slug', 'panel-2')->exists());
        $this->actingAs($admin)->post('/panel/icerik', ['website_id' => $this->website->id, 'kind' => 'post', 'title' => 'Panel']);
        $this->assertTrue(Content::where('kind', 'post')->where('slug', 'panel')->exists());
    }
}
