<?php

namespace Tests\Feature\Panel;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\ContentDraft;
use App\Models\ContentRevision;
use App\Models\Website;
use App\Services\ContentCache;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 18 — Content Engine (+ faz 23 iç bağlantı): çalışma taslağı ile canlı
 * içeriği düşürmeden düzenleme, kategori sayfaları, ilgili yazılar.
 */
class ContentEngineTest extends TestCase
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

    /** @param  array<string, mixed>  $overrides */
    private function published(string $title, string $category, array $overrides = []): Content
    {
        $content = Content::create(array_merge([
            'website_id' => $this->website->id,
            'kind' => 'post',
            'slug' => str($title)->slug()->toString(),
            'title' => $title,
            'excerpt' => 'Özet.',
            'body' => "## $title\n\nYayındaki gövde.",
            'category' => $category,
            'reading_minutes' => 1,
        ], $overrides));

        $content->forceFill(['status' => ContentStatus::PUBLISHED, 'published_at' => now()->subDay()])->save();

        return $content;
    }

    #[Test]
    public function calisma_taslagi_canli_metni_dusurmeden_akistan_gecip_birlesir(): void
    {
        $admin = $this->staff('system_admin');
        $content = $this->published('Sanal ofis rehberi', 'Sanal Ofis');

        // Yayındaki içerik doğrudan düzenlenemez; taslak açılır.
        $this->actingAs($admin)->get("/panel/icerik/{$content->id}/duzenle")->assertRedirect("/panel/icerik/{$content->id}");
        $this->actingAs($admin)->get("/panel/icerik/{$content->id}")->assertOk()->assertSee('Çalışma taslağı aç');

        $this->actingAs($admin)->post("/panel/icerik/{$content->id}/taslak")->assertRedirect("/panel/icerik/{$content->id}/taslak/duzenle");
        $draft = ContentDraft::where('content_id', $content->id)->firstOrFail();
        $this->assertSame(ContentStatus::DRAFT, $draft->status);
        $this->assertSame($content->body, $draft->body);

        // İkinci açma yenisini üretmez.
        $this->actingAs($admin)->post("/panel/icerik/{$content->id}/taslak")->assertRedirect();
        $this->assertSame(1, ContentDraft::where('content_id', $content->id)->count());

        $this->actingAs($admin)->get("/panel/icerik/{$content->id}/taslak/duzenle")->assertOk()->assertSee('Çalışma taslağını düzenle');

        $this->actingAs($admin)->put("/panel/icerik/{$content->id}/taslak", [
            'title' => 'Sanal ofis rehberi (güncel)',
            'body' => "## Güncel\n\nYeni gövde metni.",
            'category' => 'Mevzuat',
        ])->assertRedirect("/panel/icerik/{$content->id}");

        // Canlı metin değişmedi; vitrin eskisini gösterir.
        $content->refresh();
        $this->assertSame('Sanal ofis rehberi', $content->title);
        $this->assertSame(ContentStatus::PUBLISHED, $content->status);
        $this->get('/blog/'.$content->slug)->assertOk()->assertSee('Yayındaki gövde.')->assertDontSee('Yeni gövde metni.');

        // Akış: incelemeye gönder -> yayınla = birleştir.
        $this->actingAs($admin)->post("/panel/icerik/{$content->id}/taslak/incelemeye-gonder")->assertRedirect();
        $this->assertSame(ContentStatus::IN_REVIEW, $draft->fresh()->status);

        // İncelemedeki taslak düzenlenemez.
        $this->actingAs($admin)->get("/panel/icerik/{$content->id}/taslak/duzenle")->assertRedirect("/panel/icerik/{$content->id}")->assertSessionHasErrors('status');

        $revisionsBefore = ContentRevision::where('content_id', $content->id)->count();
        $this->actingAs($admin)->post("/panel/icerik/{$content->id}/taslak/yayinla")->assertRedirect("/panel/icerik/{$content->id}");

        $content->refresh();
        $this->assertSame('Sanal ofis rehberi (güncel)', $content->title);
        $this->assertSame('Mevzuat', $content->category);
        $this->assertSame(ContentStatus::PUBLISHED, $content->status, 'Birleştirme yayını düşürmez.');
        $this->assertSame($revisionsBefore + 1, ContentRevision::where('content_id', $content->id)->count());
        $this->assertNull($content->fresh()->draft);

        // Önbellek geçersiz kılındı: vitrin yeni metni gösterir.
        $this->get('http://localhost/blog/'.$content->slug)->assertOk()->assertSee('Yeni gövde metni.')->assertDontSee('Yayındaki gövde.');
    }

    #[Test]
    public function onay_gerektiren_icerigin_taslagi_onaysiz_birlesmez_ve_izinler_uygulanir(): void
    {
        $admin = $this->staff('system_admin');
        $ops = $this->staff('operations_admin'); // edit/review var; approve/publish yok
        $content = $this->published('Aydınlatma metni güncellemesi', 'Mevzuat', ['requires_approval' => true]);

        // operations_admin taslak açıp incelemeye gönderebilir.
        $this->actingAs($ops)->post("/panel/icerik/{$content->id}/taslak")->assertRedirect();
        $this->actingAs($ops)->put("/panel/icerik/{$content->id}/taslak", ['title' => 'Aydınlatma metni v2', 'body' => 'Yeni madde.'])->assertRedirect();
        $this->actingAs($ops)->post("/panel/icerik/{$content->id}/taslak/incelemeye-gonder")->assertRedirect();
        $this->actingAs($ops)->post("/panel/icerik/{$content->id}/taslak/onayla")->assertForbidden();
        $this->actingAs($ops)->post("/panel/icerik/{$content->id}/taslak/yayinla")->assertForbidden();

        // Yayıncı bile onaysız birleştiremez.
        $this->actingAs($admin)->from("/panel/icerik/{$content->id}")
            ->post("/panel/icerik/{$content->id}/taslak/yayinla")
            ->assertSessionHasErrors('status');
        $this->assertSame('Aydınlatma metni güncellemesi', $content->fresh()->title);

        // Geri gönderme gerekçe ister; not yazara görünür.
        $this->actingAs($ops)->from("/panel/icerik/{$content->id}")
            ->post("/panel/icerik/{$content->id}/taslak/geri-gonder", ['note' => ''])
            ->assertSessionHasErrors('note');
        $this->actingAs($ops)->post("/panel/icerik/{$content->id}/taslak/geri-gonder", ['note' => 'Madde numarası eksik.'])->assertRedirect();
        $this->actingAs($ops)->get("/panel/icerik/{$content->id}")->assertOk()->assertSee('Madde numarası eksik.');

        $this->actingAs($ops)->post("/panel/icerik/{$content->id}/taslak/incelemeye-gonder")->assertRedirect();
        $this->actingAs($admin)->post("/panel/icerik/{$content->id}/taslak/onayla", ['note' => 'Uygun.'])->assertRedirect();
        $this->assertSame(ContentStatus::APPROVED, $content->fresh()->draft?->status);
        $this->actingAs($admin)->post("/panel/icerik/{$content->id}/taslak/yayinla")->assertRedirect();
        $this->assertSame('Aydınlatma metni v2', $content->fresh()->title);

        // Taslak silme canlıyı etkilemez; taslak olmayan içerik için taslak açılmaz.
        $this->actingAs($admin)->post("/panel/icerik/{$content->id}/taslak")->assertRedirect();
        $this->actingAs($admin)->delete("/panel/icerik/{$content->id}/taslak")->assertRedirect("/panel/icerik/{$content->id}");
        $this->assertNull($content->fresh()->draft);
        $this->assertSame('Aydınlatma metni v2', $content->fresh()->title);

        $plainDraft = Content::create(['website_id' => $this->website->id, 'kind' => 'post', 'slug' => 'taslak', 'title' => 'Taslak yazı']);
        $this->actingAs($admin)->from("/panel/icerik/{$plainDraft->id}")
            ->post("/panel/icerik/{$plainDraft->id}/taslak")
            ->assertSessionHasErrors('status');

        // Müşteri kullanıcısı taslak akışına giremez.
        $owner = $this->owner($this->organization('Acme'));
        $this->actingAs($owner)->post("/panel/icerik/{$content->id}/taslak")->assertForbidden();
    }

    #[Test]
    public function kategori_sayfalari_ve_ilgili_yazilar(): void
    {
        $a = $this->published('Sanal ofis nedir', 'Sanal Ofis');
        $b = $this->published('Sanal ofis fiyatları', 'Sanal Ofis');
        $c = $this->published('KDV beyannamesi takvimi', 'Mevzuat');
        Content::create(['website_id' => $this->website->id, 'kind' => 'post', 'slug' => 'gizli', 'title' => 'Taslak', 'category' => 'Gizli Kategori']);

        // Liste: kategoriler yalnız yayındaki yazılardan; taslak kategorisi yok.
        $this->get('/blog')->assertOk()->assertSee('Sanal Ofis')->assertSee('Mevzuat')->assertDontSee('Gizli Kategori')
            ->assertSee('/blog/kategori/sanal-ofis', false);

        $this->get('/blog/kategori/sanal-ofis')->assertOk()
            ->assertSee('Sanal ofis nedir')->assertSee('Sanal ofis fiyatları')->assertDontSee('KDV beyannamesi takvimi')
            ->assertSee('<link rel="canonical" href="'.config('app.url').'/blog/kategori/sanal-ofis">', false);
        $this->get('/blog/kategori/gizli-kategori')->assertNotFound();
        $this->get('/blog/kategori/yok')->assertNotFound();

        // Sitemap'te kategori sayfası.
        $this->get('/sitemap.xml')->assertSee('<loc>'.config('app.url').'/blog/kategori/mevzuat</loc>', false);

        // İlgili yazılar: aynı kategori önce, sonra en yeni; yazının kendisi yok.
        $html = $this->get('/blog/'.$a->slug)->assertOk()->getContent();
        $this->assertStringContainsString('İlgili yazılar', $html);
        $this->assertStringContainsString('Sanal ofis fiyatları', $html);
        $this->assertStringContainsString('KDV beyannamesi takvimi', $html);
        $this->assertLessThan(strpos($html, 'KDV beyannamesi takvimi'), strpos($html, 'href="'.route('site.post', $b->slug).'"'), 'Aynı kategori önce gelir.');
        $this->assertSame(1, substr_count($html, 'href="'.route('site.post', $a->slug).'"'), 'Yazı kendini ilgili olarak listelemez.');
        $this->assertStringContainsString('href="'.route('site.category', 'sanal-ofis').'"', $html);

        // Tek yazı: ilgili bölümü basılmaz.
        $b->delete();
        $c->delete();
        app(ContentCache::class)->invalidate($this->website);
        $this->get('http://localhost/blog/'.$a->slug)->assertOk()->assertDontSee('İlgili yazılar');
    }
}
