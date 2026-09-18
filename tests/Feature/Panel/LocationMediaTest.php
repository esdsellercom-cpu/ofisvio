<?php

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\Location;
use App\Models\LocationMedia;
use App\Models\Media;
use App\Security\MalwareScanner;
use App\Security\ScanResult;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 3 — Lokasyon medya yönetimi: karantina zinciri (MIME → uzantı → sihirli bayt → boyut →
 * ClamAV fail-closed → sha256 → onay → public + varyantlar), admin işlemleri (yükle, kapak,
 * galeri, sırala, alt/başlık/altyazı, dosya değiştir, kaldır), vitrin (kapak + galeri, srcset/
 * sizes/lazy; kodda görsel yolu yok), önbellek geçersizleme, yetki ve lokasyon sınırı.
 */
class LocationMediaTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    private Location $location;

    private object $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(LocationSeeder::class);
        $this->seed(WebsiteSeeder::class);
        $this->seed(SiteBlockSeeder::class);
        Storage::fake('public');
        Storage::fake('private');
        $this->location = Location::published()->firstOrFail();

        // Sonucu değiştirilebilir tarayıcı: rota controller'ı istekler arasında memo'lar; tek örnek en başta bağlanır.
        $this->scanner = new class implements MalwareScanner
        {
            public ?ScanResult $result = null;

            public function scan(string $path): ScanResult
            {
                return $this->result ?? ScanResult::clean();
            }
        };
        $this->app->instance(MalwareScanner::class, $this->scanner);
    }

    private function base(): string
    {
        return "/panel/geo/lokasyon/{$this->location->slug}/gorseller";
    }

    #[Test]
    public function admin_yukler_kapak_yapar_galeriye_ekler_siralar_duzenler_degistirir_ve_vitrin_yansir(): void
    {
        $ops = $this->staff('operations_admin'); // geo.edit
        $base = $this->base();

        $this->actingAs($ops)->get($base)->assertOk()->assertSee('Görsel yükle')->assertSee('Kapak seçilmedi');

        // 1) Görsel yükle (galeri): karantina zinciri geçer, public diske UUID adla, 480/960/1600 varyant.
        $this->actingAs($ops)->post($base, ['file' => UploadedFile::fake()->image('lobi.jpg', 2000, 1250), 'category' => 'gallery', 'alt' => 'Lobi', 'title' => 'Giriş lobisi', 'caption' => 'Zemin kat'])
            ->assertRedirect($base)->assertSessionHasNoErrors();
        $media = Media::query()->firstOrFail();
        $this->assertSame(['approved', 2000, 1250, 'image/jpeg', 'Lobi', 'Giriş lobisi', 'Zemin kat'], [$media->status, $media->width, $media->height, $media->mime_type, $media->alt, $media->title, $media->caption]);
        $this->assertMatchesRegularExpression('~^media/\d+/[0-9a-f-]{36}\.jpg$~', $media->path);
        $this->assertSame([480, 960, 1600], array_column($media->variants, 'w'));
        Storage::disk('public')->assertExists($media->path);
        foreach ($media->variants as $v) {
            Storage::disk('public')->assertExists($v['path']);
        }
        $this->assertCount(0, Storage::disk('private')->allFiles()); // karantina temizlendi
        $link = LocationMedia::query()->firstOrFail();
        $this->assertSame(['gallery', 1, true], [$link->category, $link->sort_order, $link->is_primary]);
        $this->assertTrue(AuditLog::query()->where('action', 'media.uploaded')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'location_media.attached')->exists());

        // 2) Kapak yap → locations.cover_media_id + cover bağı; vitrin kapağı basar (srcset/sizes/lazy değil: eager, kapak LCP).
        $this->actingAs($ops)->post("{$base}/{$link->id}/kapak")->assertRedirect($base)->assertSessionHasNoErrors();
        $this->assertSame($media->id, (int) $this->location->fresh()->cover_media_id);
        $page = $this->get('/lokasyon/'.$this->location->slug)->assertOk()->getContent();
        $this->assertStringContainsString('srcset="'.Storage::disk('public')->url($media->variants[0]['path']).' 480w', $page);
        $this->assertStringContainsString($media->url().' 2000w', $page);
        $this->assertStringContainsString('sizes="(max-width: 700px) 100vw, 60vw"', $page);
        $this->assertStringContainsString('alt="Lobi"', $page);
        $this->assertStringContainsString('loading="eager"', $page);
        $this->assertStringContainsString('property="og:image" content="'.$media->absoluteUrlFor(1600), $page);
        $this->assertStringContainsString('<figcaption class="small muted" style="padding:8px 12px">Zemin kat', $page);
        // Ana sayfa ve lokasyon listesi kartları: kapak + lazy.
        $home = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString($media->urlFor(960), $home);
        $this->assertStringContainsString('sizes="(max-width: 640px) 100vw, 320px"', $home);
        $this->assertStringContainsString('loading="lazy"', $home);
        $this->assertStringContainsString($media->urlFor(960), $this->get('/lokasyonlar')->assertOk()->getContent());

        // 3) Galeriye ekle (iç mekân ×2, toplantı odası); aynı içerik ikinci kez → yinelenen kayıt yok.
        $this->actingAs($ops)->post($base, ['file' => UploadedFile::fake()->image('ic1.png', 1200, 800), 'category' => 'interior', 'alt' => 'Açık ofis'])->assertSessionHasNoErrors();
        $this->actingAs($ops)->post($base, ['file' => UploadedFile::fake()->image('ic2.png', 1000, 700), 'category' => 'interior', 'alt' => 'Sessiz oda'])->assertSessionHasNoErrors();
        $this->actingAs($ops)->post($base, ['file' => UploadedFile::fake()->image('oda.webp', 900, 600), 'category' => 'meeting_room', 'alt' => 'Toplantı odası'])->assertSessionHasNoErrors();
        $this->assertSame(4, Media::count());
        $this->assertSame(['ic1.png', 'ic2.png'], LocationMedia::query()->where('category', 'interior')->orderBy('sort_order')->with('media')->get()->map(fn ($l) => $l->media->original_name)->all());
        $page = $this->get('/lokasyon/'.$this->location->slug)->assertOk()->getContent();
        $this->assertStringContainsString('İç mekân', $page);
        $this->assertStringContainsString('Toplantı odası', $page);
        $this->assertStringContainsString('alt="Açık ofis"', $page);
        $this->assertLessThan(strpos($page, 'alt="Sessiz oda"'), strpos($page, 'alt="Açık ofis"'));

        // 4) Sırayı değiştir: ok ile ve sürükle-bırak listesiyle; vitrin sırası değişir (önbellek düştü).
        [$a, $b] = LocationMedia::query()->where('category', 'interior')->orderBy('sort_order')->pluck('id')->all();
        $this->actingAs($ops)->post("{$base}/{$b}/tasi", ['direction' => 'up'])->assertRedirect($base)->assertSessionHasNoErrors();
        $this->assertSame([$b, $a], LocationMedia::query()->where('category', 'interior')->orderBy('sort_order')->pluck('id')->all());
        $page = $this->get('/lokasyon/'.$this->location->slug)->assertOk()->getContent();
        $this->assertLessThan(strpos($page, 'alt="Açık ofis"'), strpos($page, 'alt="Sessiz oda"'));
        $this->actingAs($ops)->post("{$base}/sirala", ['category' => 'interior', 'order' => "{$a},{$b}"])->assertRedirect($base);
        $this->assertSame([$a, $b], LocationMedia::query()->where('category', 'interior')->orderBy('sort_order')->pluck('id')->all());
        // Birincil: kategoride seçilir; kaldırılınca sıradaki birincil olur.
        $this->actingAs($ops)->post("{$base}/{$b}/birincil")->assertRedirect($base);
        $this->assertTrue(LocationMedia::query()->find($b)->is_primary);
        $this->assertFalse(LocationMedia::query()->find($a)->is_primary);

        // 5) Alt metin / başlık / altyazı değiştir (medya kaydında; tüm kullanım yerlerine yansır).
        $this->actingAs($ops)->put("{$base}/{$link->id}", ['alt' => 'Giriş lobisi, gündüz', 'title' => 'Lobi', 'caption' => 'Resepsiyon karşısı'])->assertRedirect($base)->assertSessionHasNoErrors();
        $this->assertSame(['Giriş lobisi, gündüz', 'Lobi', 'Resepsiyon karşısı'], [$media->fresh()->alt, $media->fresh()->title, $media->fresh()->caption]);
        $this->assertStringContainsString('alt="Giriş lobisi, gündüz"', $this->get('/lokasyon/'.$this->location->slug)->getContent());
        $this->assertTrue(AuditLog::query()->where('action', 'media.meta_updated')->exists());

        // 6) Görseli değiştir: yeni dosya zincirden geçer, bağ + meta + kapak korunur, eski dosya (kullanılmıyorsa) silinir.
        $oldPath = $media->path;
        $this->actingAs($ops)->post("{$base}/{$link->id}/degistir", ['file' => UploadedFile::fake()->image('lobi-yeni.jpg', 1800, 1200)])->assertRedirect($base)->assertSessionHasNoErrors();
        $link->refresh();
        $this->assertNotSame($media->id, (int) $link->media_id);
        $this->assertSame((int) $link->media_id, (int) $this->location->fresh()->cover_media_id);
        $this->assertSame('Giriş lobisi, gündüz', $link->media->alt);
        $this->assertNull(Media::query()->find($media->id));
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($link->media->path);
        $this->assertStringContainsString($link->media->url(), $this->get('/lokasyon/'.$this->location->slug)->getContent());

        // 7) Kaldır: kapak bağı kaldırılınca kapak boşalır → vitrin boş durum kutusu (ticari içerik değil); dosya silinir.
        $cover = LocationMedia::query()->where('category', 'cover')->firstOrFail();
        $galleryLink = LocationMedia::query()->where('category', 'gallery')->firstOrFail();
        $this->actingAs($ops)->delete("{$base}/{$cover->id}")->assertRedirect($base)->assertSessionHasNoErrors();
        $this->assertNull($this->location->fresh()->cover_media_id);
        $this->assertNotNull(Media::query()->find($galleryLink->media_id)); // galeride hâlâ kullanılıyor
        $this->actingAs($ops)->delete("{$base}/{$galleryLink->id}")->assertRedirect($base);
        $this->assertNull(Media::query()->find($galleryLink->media_id));
        $page = $this->get('/lokasyon/'.$this->location->slug)->assertOk()->getContent();
        $this->assertStringContainsString('class="shot"', $page);
        $this->assertStringNotContainsString('loading="eager"', $page);
    }

    #[Test]
    public function karantina_zinciri_sahte_uzanti_bozuk_dosya_tarayici_arizasi_ve_enfekte_dosyayi_yayinlamaz(): void
    {
        $ops = $this->staff('operations_admin');
        $base = $this->base();

        // .jpg uzantılı ama PHP içerikli dosya: MIME aşamasında düşer.
        $this->actingAs($ops)->from($base)->post($base, ['file' => UploadedFile::fake()->createWithContent('kotu.jpg', "<?php echo 'x';"), 'category' => 'gallery'])->assertSessionHasErrors('file');
        // Boyut sınırı (validation 10 MB; üstü otomatik küçültme öncesinde reddedilir).
        $this->actingAs($ops)->from($base)->post($base, ['file' => UploadedFile::fake()->create('buyuk.jpg', 11000, 'image/jpeg'), 'category' => 'gallery'])->assertSessionHasErrors('file');
        // Tarayıcı erişilemez → fail-closed: dosya yayınlanmaz, kayıt yok, karantina boş.
        $this->scanner->result = ScanResult::unavailable('clamd bağlantısı yok');
        $this->actingAs($ops)->from($base)->post($base, ['file' => UploadedFile::fake()->image('temiz.jpg', 800, 600), 'category' => 'gallery'])->assertSessionHasErrors('file');
        // Enfekte: reddedilir, denetim izi aşamayı söyler.
        $this->scanner->result = ScanResult::infected('Eicar-Test-Signature');
        $this->actingAs($ops)->from($base)->post($base, ['file' => UploadedFile::fake()->image('virus.png', 800, 600), 'category' => 'gallery'])->assertSessionHasErrors('file');
        $this->scanner->result = null;

        $this->assertSame(0, Media::count());
        $this->assertSame(0, LocationMedia::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame([], Storage::disk('private')->allFiles());
        $this->assertSame(['mime', 'scanner_unavailable', 'infected'], AuditLog::query()->where('action', 'media.rejected')->orderBy('id')->get()->map(fn ($l) => $l->after['stage'])->all());
        // Geçersiz kategori.
        $this->actingAs($ops)->from($base)->post($base, ['file' => UploadedFile::fake()->image('a.jpg', 800, 600), 'category' => 'banner'])->assertSessionHasErrors('category');
    }

    #[Test]
    public function yetki_ve_lokasyon_siniri(): void
    {
        $ops = $this->staff('operations_admin');
        $finance = $this->staff('finance_admin'); // geo.edit yok
        $other = Location::query()->where('id', '!=', $this->location->id)->firstOrFail();
        $base = $this->base();

        $this->actingAs($finance)->get($base)->assertForbidden();
        $this->actingAs($finance)->post($base, ['file' => UploadedFile::fake()->image('a.jpg', 800, 600), 'category' => 'gallery'])->assertForbidden();

        $this->actingAs($ops)->post($base, ['file' => UploadedFile::fake()->image('a.jpg', 800, 600), 'category' => 'gallery'])->assertSessionHasNoErrors();
        $link = LocationMedia::query()->firstOrFail();
        // Başka lokasyonun yolu ile bu bağa erişilemez.
        $this->actingAs($ops)->from($base)->put("/panel/geo/lokasyon/{$other->slug}/gorseller/{$link->id}", ['alt' => 'x'])->assertSessionHasErrors('media');
        $this->actingAs($ops)->from($base)->delete("/panel/geo/lokasyon/{$other->slug}/gorseller/{$link->id}")->assertSessionHasErrors('media');
        $this->assertNotNull($link->fresh());
        // Müşteri kullanıcısı giremez.
        $acme = $this->organization('Acme');
        $this->actingAs($this->owner($acme))->withContext($acme)->get($base)->assertForbidden();
        // Görsel silinemez (medya kütüphanesi): lokasyonda kullanımda.
        $this->actingAs($this->staff('system_admin'))->from('/panel/icerik/medya')->delete('/panel/icerik/medya/'.$link->media_id)->assertSessionHasErrors();
    }
}
