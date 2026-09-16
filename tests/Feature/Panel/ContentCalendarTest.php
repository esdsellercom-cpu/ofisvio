<?php

namespace Tests\Feature\Panel;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Website;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 24 — İçerik takvimi: aylık ızgara (zamanlanmış + yayında), iş hattı,
 * gecikmiş zamanlama uyarısı, ay gezinme, izin.
 */
class ContentCalendarTest extends TestCase
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
        Carbon::setTestNow('2026-09-16 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $attrs */
    private function content(string $title, ContentStatus $status, array $attrs = []): Content
    {
        $content = Content::create(['website_id' => $this->website->id, 'kind' => 'post', 'slug' => str($title)->slug()->toString(), 'title' => $title, 'body' => 'Gövde.']);
        $content->forceFill(array_merge(['status' => $status], $attrs))->save();

        return $content;
    }

    #[Test]
    public function takvim_zamanlanmis_ve_yayindaki_icerigi_gunune_yerlestirir(): void
    {
        $admin = $this->staff('system_admin');

        $this->content('Eylül yazısı', ContentStatus::PUBLISHED, ['published_at' => '2026-09-03 09:00:00']);
        $this->content('Zamanlanmış yazı', ContentStatus::SCHEDULED, ['scheduled_for' => '2026-09-25 08:30:00']);
        $this->content('Ekim yazısı', ContentStatus::SCHEDULED, ['scheduled_for' => '2026-10-02 08:00:00']);
        $this->content('Yalnız taslak', ContentStatus::DRAFT);

        $html = $this->actingAs($admin)->get('/panel/icerik/takvim')->assertOk()->getContent();
        $this->assertStringContainsString('Takvim — Eylül 2026', $html);
        $this->assertStringContainsString('Eylül yazısı', $html);
        $this->assertStringContainsString('Zamanlanmış yazı', $html);
        $this->assertStringContainsString('08:30', $html);
        $this->assertStringNotContainsString('Ekim yazısı', $html);
        $this->assertStringContainsString('Bu ay 2 kayıt', $html);
        $this->assertStringContainsString('is-today', $html);
        $this->assertStringNotContainsString('Gecikmiş zamanlama', $html);

        // Taslak takvimde değil, iş hattında.
        $this->assertStringContainsString('Yalnız taslak', $html);
        $this->assertMatchesRegularExpression('/<dt>Taslak<\/dt><dd>7<\/dd>/', $html); // 6 seed taslağı (3 yazı + 3 sayfa) + 1

        // Ay gezinme + geçersiz ay parametresi bu aya düşer.
        $this->actingAs($admin)->get('/panel/icerik/takvim?ay=2026-10')->assertOk()->assertSee('Ekim 2026')->assertSee('Ekim yazısı')->assertDontSee('Eylül yazısı');
        $this->actingAs($admin)->get('/panel/icerik/takvim?ay=bozuk')->assertOk()->assertSee('Takvim — Eylül 2026');
        $this->actingAs($admin)->get('/panel/icerik/takvim?ay=2026-13')->assertOk()->assertSee('Takvim — Eylül 2026');

        // Liste sayfasından bağlantı.
        $this->actingAs($admin)->get('/panel/icerik')->assertOk()->assertSee('/panel/icerik/takvim', false);
    }

    #[Test]
    public function haftalik_gorunum_ve_editor_is_yuku(): void
    {
        $admin = $this->staff('system_admin');
        $editor = $this->staff('operations_admin');

        $this->content('Bu hafta', ContentStatus::SCHEDULED, ['scheduled_for' => '2026-09-18 09:00:00']); // Cuma (16'sı Çarşamba)
        $this->content('Gelecek hafta', ContentStatus::SCHEDULED, ['scheduled_for' => '2026-09-23 09:00:00']);
        $this->content('Editörün taslağı', ContentStatus::DRAFT, ['author_id' => $editor->id]);
        $this->content('Editörün ikinci taslağı', ContentStatus::IN_REVIEW, ['author_id' => $editor->id]);

        $html = $this->actingAs($admin)->get('/panel/icerik/takvim?hafta=2026-09-16')->assertOk()->getContent();
        $this->assertStringContainsString('14 Eylül – 20 Eylül 2026', $html);
        $this->assertStringContainsString('Bu hafta', $html);
        $this->assertStringNotContainsString('Gelecek hafta</a>', $html);
        $this->assertStringContainsString('Sonraki hafta', $html);
        $this->assertStringContainsString('is-week', $html);
        $this->assertStringContainsString('Bu hafta 1 kayıt', $html);

        // Sonraki hafta bağlantısı ve bozuk parametre aylık görünüme düşer.
        $this->actingAs($admin)->get('/panel/icerik/takvim?hafta=2026-09-23')->assertOk()->assertSee('Gelecek hafta')->assertDontSee('Bu hafta</a>');
        $this->actingAs($admin)->get('/panel/icerik/takvim?hafta=bozuk')->assertOk()->assertSee('Takvim — Eylül 2026');

        // Editör iş yükü: yazar başına sayılar; toplam sıralı.
        $html = $this->actingAs($admin)->get('/panel/icerik/takvim')->assertOk()->getContent();
        $this->assertStringContainsString('Editör iş yükü', $html);
        $this->assertMatchesRegularExpression('/'.preg_quote($editor->name, '/').'<\/td>\s*<td class="num mono">1<\/td>\s*<td class="num mono">1<\/td>/', $html);
    }

    #[Test]
    public function gecikmis_zamanlama_uyarilir_ve_komut_calisinca_kaybolur(): void
    {
        $admin = $this->staff('system_admin');
        $late = $this->content('Gecikmiş yazı', ContentStatus::SCHEDULED, ['scheduled_for' => '2026-09-15 07:00:00']);

        $html = $this->actingAs($admin)->get('/panel/icerik/takvim')->assertOk()->getContent();
        $this->assertStringContainsString('Gecikmiş zamanlama', $html);
        $this->assertStringContainsString('content:publish-scheduled', $html);
        $this->assertStringContainsString('badge--danger', $html);

        $this->artisan('content:publish-scheduled')->assertSuccessful();
        $this->assertSame(ContentStatus::PUBLISHED, $late->fresh()->status);

        $html = $this->actingAs($admin)->get('/panel/icerik/takvim')->assertOk()->getContent();
        $this->assertStringNotContainsString('Gecikmiş zamanlama', $html);
        $this->assertStringContainsString('Gecikmiş yazı', $html); // artık yayın gününde
    }

    #[Test]
    public function calisma_taslaklari_is_hattinda_gorunur_ve_musteri_giremez(): void
    {
        $admin = $this->staff('system_admin');
        $live = $this->content('Canlı yazı', ContentStatus::PUBLISHED, ['published_at' => '2026-08-01 09:00:00']);
        $this->actingAs($admin)->post("/panel/icerik/{$live->id}/taslak")->assertRedirect();

        $html = $this->actingAs($admin)->get('/panel/icerik/takvim')->assertOk()->getContent();
        $this->assertStringContainsString('Çalışma taslakları', $html);
        $this->assertMatchesRegularExpression('/<dt>Çalışma taslağı<\/dt><dd>1<\/dd>/', $html);

        // operations_admin (content.edit) görür; müşteri sahibi göremez.
        $this->actingAs($this->staff('operations_admin'))->get('/panel/icerik/takvim')->assertOk();
        $this->actingAs($this->owner($this->organization('Acme')))->get('/panel/icerik/takvim')->assertForbidden();
    }
}
