<?php

namespace Tests\Feature\Panel;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\ContentDraft;
use App\Models\ContentRevision;
use App\Models\Website;
use App\Services\ContentCache;
use App\Services\ContentService;
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

        // Slug çakışması: taslak beklerken slug'ı başka içerik almışsa birleştirme tekil slug üretir.
        $this->actingAs($admin)->post("/panel/icerik/{$content->id}/taslak")->assertRedirect();
        $this->actingAs($admin)->put("/panel/icerik/{$content->id}/taslak", ['title' => 'Sanal ofis rehberi (güncel)', 'slug' => 'yeni-slug', 'body' => 'Gövde.'])->assertRedirect();
        Content::create(['website_id' => $this->website->id, 'kind' => 'post', 'slug' => 'yeni-slug', 'title' => 'Araya giren']);
        $this->actingAs($admin)->post("/panel/icerik/{$content->id}/taslak/incelemeye-gonder")->assertRedirect();
        $this->actingAs($admin)->post("/panel/icerik/{$content->id}/taslak/yayinla")->assertRedirect("/panel/icerik/{$content->id}")->assertSessionHasNoErrors();
        $this->assertSame('yeni-slug-2', $content->fresh()->slug);
        $this->assertSame('Gövde.', $content->fresh()->body);

        // Önbellek geçersiz kılındı: vitrin yeni metni gösterir.
        $this->get('http://localhost/blog/yeni-slug-2')->assertOk()->assertSee('Gövde.')->assertDontSee('Yayındaki gövde.');
        $this->get('http://localhost/blog/sanal-ofis-rehberi')->assertNotFound();
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

        // Onaylı taslak zamanlanabilir; onay korunur ve zamanlayıcı birleştirir. Onaysız zamanlama reddedilir.
        $this->actingAs($admin)->post("/panel/icerik/{$content->id}/taslak")->assertRedirect();
        $this->actingAs($admin)->put("/panel/icerik/{$content->id}/taslak", ['title' => 'Aydınlatma metni v3', 'body' => 'Üçüncü sürüm.'])->assertRedirect();
        $this->actingAs($admin)->post("/panel/icerik/{$content->id}/taslak/incelemeye-gonder")->assertRedirect();
        $this->actingAs($admin)->from("/panel/icerik/{$content->id}")
            ->post("/panel/icerik/{$content->id}/taslak/zamanla", ['scheduled_for' => now()->addDay()->format('Y-m-d H:i')])
            ->assertSessionHasErrors('status');
        $this->actingAs($admin)->post("/panel/icerik/{$content->id}/taslak/onayla")->assertRedirect();
        $this->actingAs($admin)->post("/panel/icerik/{$content->id}/taslak/zamanla", ['scheduled_for' => now()->addDay()->format('Y-m-d H:i')])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(ContentStatus::SCHEDULED, $content->fresh()->draft?->status);
        $this->assertSame($admin->id, (int) $content->fresh()->draft?->approved_by);
        $this->travel(25)->hours();
        $this->artisan('content:publish-scheduled')->assertSuccessful();
        $this->travelBack();
        $this->assertNull($content->fresh()->draft);
        $this->assertSame('Aydınlatma metni v3', $content->fresh()->title);

        // Taslak silme canlıyı etkilemez; taslak olmayan içerik için taslak açılmaz.
        $this->actingAs($admin)->post("/panel/icerik/{$content->id}/taslak")->assertRedirect();
        $this->actingAs($admin)->delete("/panel/icerik/{$content->id}/taslak")->assertRedirect("/panel/icerik/{$content->id}");
        $this->assertNull($content->fresh()->draft);
        $this->assertSame('Aydınlatma metni v3', $content->fresh()->title);

        $plainDraft = Content::create(['website_id' => $this->website->id, 'kind' => 'post', 'slug' => 'taslak', 'title' => 'Taslak yazı']);
        $this->actingAs($admin)->from("/panel/icerik/{$plainDraft->id}")
            ->post("/panel/icerik/{$plainDraft->id}/taslak")
            ->assertSessionHasErrors('status');

        // Müşteri kullanıcısı taslak akışına giremez.
        $owner = $this->owner($this->organization('Acme'));
        $this->actingAs($owner)->post("/panel/icerik/{$content->id}/taslak")->assertForbidden();
    }

    #[Test]
    public function etiketler_normalize_edilir_etiket_sayfasi_ve_ic_baglanti_onerisi(): void
    {
        $admin = $this->staff('system_admin');

        // Etiketler: küçük harf, kırpılmış, tekil, en fazla 10.
        $this->actingAs($admin)->post('/panel/icerik', [
            'website_id' => $this->website->id, 'kind' => 'post', 'title' => 'Sanal ofis ile KDV avantajı',
            'body' => 'Gövde.', 'category' => 'Mevzuat', 'tags' => ' Sanal Ofis, KDV ,kdv, , Tescil',
        ])->assertRedirect();
        $post = Content::where('title', 'Sanal ofis ile KDV avantajı')->firstOrFail();
        $this->assertSame(['sanal ofis', 'kdv', 'tescil'], $post->tags);
        $this->assertNull(ContentService::normalizeTags(' , '));
        $this->assertCount(10, ContentService::normalizeTags(implode(',', range(1, 15))) ?? []);

        $post->forceFill(['status' => ContentStatus::PUBLISHED, 'published_at' => now()->subDay()])->save();
        $other = $this->published('Tescil adresi nasıl alınır', 'Sanal Ofis', ['tags' => ['tescil', 'adres']]);
        $far = $this->published('Hibrit çalışma rehberi', 'Kültür');
        app(ContentCache::class)->invalidate($this->website);

        // Etiket sayfası + sitemap + yazı altı etiketleri.
        $this->get('/blog/etiket/tescil')->assertOk()->assertSee('#tescil')->assertSee('Sanal ofis ile KDV avantajı')->assertSee('Tescil adresi nasıl alınır')->assertDontSee('Hibrit çalışma rehberi');
        $this->get('/blog/etiket/yok')->assertNotFound();
        $this->get('/sitemap.xml')->assertSee('<loc>'.config('app.url').'/blog/etiket/kdv</loc>', false);
        $this->get('/blog/'.$post->slug)->assertOk()->assertSee('href="'.route('site.tag', 'sanal-ofis').'"', false);

        // İç bağlantı önerisi: ortak etiket (tescil) ×3 + başlık kelimesi; uzak yazı önerilmez.
        $html = $this->actingAs($admin)->get("/panel/icerik/{$post->id}")->assertOk()->getContent();
        $this->assertStringContainsString('İç bağlantı önerileri', $html);
        $this->assertStringContainsString('ortak etiket: tescil', $html);
        $this->assertStringContainsString('[Tescil adresi nasıl alınır](/blog/'.$other->slug.')', $html);
        $this->assertStringNotContainsString('Hibrit çalışma rehberi', $html);

        // Çalışma taslağı etiketleri taşır ve birleştirince yazar.
        $this->actingAs($admin)->post("/panel/icerik/{$post->id}/taslak")->assertRedirect();
        $this->actingAs($admin)->put("/panel/icerik/{$post->id}/taslak", ['title' => $post->title, 'body' => 'Gövde.', 'tags' => 'kdv, muhasebe'])->assertRedirect();
        $this->actingAs($admin)->post("/panel/icerik/{$post->id}/taslak/incelemeye-gonder")->assertRedirect();
        $this->actingAs($admin)->post("/panel/icerik/{$post->id}/taslak/yayinla")->assertRedirect();
        $this->assertSame(['kdv', 'muhasebe'], $post->fresh()->tags);
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
