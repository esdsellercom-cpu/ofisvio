<?php

namespace Tests\Feature\Panel;

use App\Enums\ContentStatus;
use App\Models\AuditLog;
use App\Models\Content;
use App\Models\Media;
use App\Models\Website;
use App\Security\MalwareScanner;
use App\Security\ScanResult;
use App\Services\ContentCache;
use App\Services\MediaService;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 30 — Medya kütüphanesi: gerçek görsel baytları, MIME/sihirli bayt/boyut/
 * tarama zinciri, sha256 yinelenen engeli, kapak ve hero vitrine yansır (og:image),
 * kullanımdaki görsel silinemez, yabancı site 404, izinler.
 */
class MediaLibraryTest extends TestCase
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
        Storage::fake('public');
        $this->site = Website::query()->default()->firstOrFail();
    }

    /** Sonucu değiştirilebilir tarayıcı: rota controller'ı istekler arasında memo'lar, bu yüzden tek örnek en başta bağlanır. */
    private object $scanner;

    private function stubScanner(): void
    {
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

    /** Gerçek 1×1 PNG (sihirli bayt doğrulaması bunu ister; fake()->image GD gerektirir). */
    private function png(string $name = 'kapak.png'): UploadedFile
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);

        return UploadedFile::fake()->createWithContent($name, (string) $bytes);
    }

    #[Test]
    public function gorsel_yuklenir_kapak_ve_hero_olarak_vitrine_yansir(): void
    {
        $admin = $this->staff('system_admin');

        $this->actingAs($admin)->get('/panel/icerik/medya')->assertOk()->assertSee('Medya kütüphanesi');
        $this->actingAs($admin)->post('/panel/icerik/medya', ['file' => $this->png(), 'alt' => 'Levent şubesi'])->assertRedirect('/panel/icerik/medya?website='.$this->site->id)->assertSessionHasNoErrors();
        $media = Media::firstOrFail();
        $this->assertSame('image/png', $media->mime_type);
        $this->assertSame([1, 1], [$media->width, $media->height]);
        $this->assertSame(64, strlen($media->checksum_sha256));
        Storage::disk('public')->assertExists($media->path);

        // Aynı bayt ikinci kez: yeni kayıt yok.
        $this->actingAs($admin)->post('/panel/icerik/medya', ['file' => $this->png('ayni.png')])->assertRedirect();
        $this->assertSame(1, Media::count());

        // Kapak: içerik formundan; vitrin liste + yazı sayfası + og:image.
        $this->actingAs($admin)->post('/panel/icerik', ['website_id' => $this->site->id, 'kind' => 'post', 'title' => 'Kapaklı yazı', 'body' => 'Gövde.', 'cover_media_id' => $media->id])->assertRedirect()->assertSessionHasNoErrors();
        $post = Content::where('slug', 'kapakli-yazi')->firstOrFail();
        $this->assertSame($media->id, $post->cover_media_id);
        $this->assertSame($media->url(), $post->cover_url);
        $post->forceFill(['status' => ContentStatus::PUBLISHED, 'published_at' => now()->subDay()])->save();
        app(ContentCache::class)->invalidate($this->site);

        $this->get('/blog')->assertOk()->assertSee('src="'.$media->url().'"', false)->assertDontSee('kapak · 800×500');
        // og:image mutlak (paylaşım botları bağıl adres okumaz), <img> bağıl.
        $this->get('/blog/kapakli-yazi')->assertOk()->assertSee('<meta property="og:image" content="'.$media->absoluteUrl().'">', false)->assertSee('alt="Levent şubesi"', false);

        // Hero (website.manage).
        // Site görseli yokken marka illüstrasyonu (faz 53); görsel yüklenince o basılır, illüstrasyon kalkar.
        $this->get('/')->assertOk()->assertSee('illustrations/hero-office.svg');
        $this->actingAs($admin)->put("/panel/websiteler/{$this->site->id}/hero", ['hero_media_id' => $media->id])->assertRedirect();
        $this->get('http://localhost/')->assertOk()->assertDontSee('illustrations/hero-office.svg')->assertSee('src="'.$media->url().'"', false);

        // Kullanımdaki görsel silinemez.
        $this->actingAs($admin)->from('/panel/icerik/medya')->delete("/panel/icerik/medya/{$media->id}")->assertSessionHasErrors('file');
        $this->assertNotNull(Media::find($media->id));

        // Kapak ve hero kaldırılınca silinir; dosya da gider.
        $this->actingAs($admin)->put("/panel/websiteler/{$this->site->id}/hero", ['hero_media_id' => ''])->assertRedirect();
        $post->forceFill(['status' => ContentStatus::DRAFT])->save();
        $this->actingAs($admin)->put("/panel/icerik/{$post->id}", ['title' => 'Kapaklı yazı', 'body' => 'Gövde.', 'cover_media_id' => ''])->assertRedirect();
        $this->assertNull($post->fresh()->cover_url);
        $this->actingAs($admin)->delete("/panel/icerik/medya/{$media->id}")->assertRedirect();
        $this->assertNull(Media::find($media->id));
        Storage::disk('public')->assertMissing($media->path);
    }

    /**
     * Adresler host'a göre bağıl: vitrin hangi alan adından açılırsa açılsın görsel aynı origin'den gelir (CSP
     * `img-src 'self'`); paylaşım görselleri ise mutlak. Büyük yükleme otomatik küçültülür (uzun kenar MAX_EDGE),
     * responsive varyantlar üretilir; aynı büyük dosya ikinci kez yüklenince yinelenen sayılır.
     */
    #[Test]
    public function adresler_bagil_paylasim_mutlak_ve_buyuk_gorsel_kucultulur(): void
    {
        $admin = $this->staff('system_admin');

        $this->actingAs($admin)->post('/panel/icerik/medya', ['file' => UploadedFile::fake()->image('buyuk.jpg', 4800, 2400), 'alt' => 'Geniş açı'])->assertRedirect()->assertSessionHasNoErrors();
        $media = Media::firstOrFail();
        $this->assertSame([MediaService::MAX_EDGE, MediaService::MAX_EDGE / 2], [$media->width, $media->height], 'uzun kenar MAX_EDGE\'e iner, oran korunur');
        $this->assertSame(MediaService::MAX_EDGE, getimagesize(Storage::disk('public')->path($media->path))[0] ?? null, 'diskteki dosya da küçültülmüş olmalı');
        $this->assertLessThan(MediaService::MAX_BYTES, $media->size_bytes);
        $this->assertSame([480, 960, 1600], array_column((array) $media->variants, 'w'));
        $this->assertStringStartsWith('/storage/media/', $media->url());
        $this->assertStringStartsWith('/storage/media/', $media->urlFor(960));
        $this->assertSame(rtrim((string) config('app.url'), '/').$media->url(), $media->absoluteUrl(), 'istek dışında APP_URL ile tamamlanır');
        $this->assertSame(1, AuditLog::query()->where('action', 'media.uploaded')->count());

        // Aynı dosya yeniden: yinelenen (sha256 orijinal baytlardan).
        $this->actingAs($admin)->post('/panel/icerik/medya', ['file' => UploadedFile::fake()->image('buyuk.jpg', 4800, 2400)])->assertRedirect();
        $this->assertSame(1, Media::count());

        // Küçük görsel dokunulmaz.
        $this->actingAs($admin)->post('/panel/icerik/medya', ['file' => UploadedFile::fake()->image('kucuk.png', 800, 500)])->assertRedirect()->assertSessionHasNoErrors();
        $small = Media::orderByDesc('id')->firstOrFail();
        $this->assertSame([800, 500], [$small->width, $small->height]);

        // Hero olarak bağlanınca farklı Host'tan açılan vitrinde de <img src> bağıldır; og:image o Host ile mutlaktır.
        $this->actingAs($admin)->put("/panel/websiteler/{$this->site->id}/hero", ['hero_media_id' => $media->id])->assertRedirect();
        $home = $this->get('http://127.0.0.1/')->assertOk()->getContent();
        $this->assertStringContainsString('src="'.$media->url().'"', $home);
        $this->assertStringContainsString('<meta property="og:image" content="http://127.0.0.1'.$media->url().'">', $home);
        $this->assertStringNotContainsString('http://localhost/storage/', $home);
    }

    #[Test]
    public function zincir_uzanti_sihirli_bayt_tarama_ve_site_siniri(): void
    {
        $admin = $this->staff('system_admin');
        $ops = $this->staff('operations_admin'); // content.edit var, publish yok
        $this->stubScanner();

        // Uzantı png ama içerik metin: MIME/sihirli bayt reddeder, dosya yazılmaz.
        $this->actingAs($admin)->from('/panel/icerik/medya')->post('/panel/icerik/medya', ['file' => UploadedFile::fake()->createWithContent('sahte.png', 'bu bir görsel değil')])->assertSessionHasErrors('file');
        $this->assertSame(0, Media::count());
        $this->assertSame([], Storage::disk('public')->allFiles());

        // Audit F-19: kılık değiştirmiş dosyalar — PHP/HTML/SVG/JS gövdesi ne uzantı ne çift uzantı ile geçer; PNG başlığı + PHP
        // kuyruğu (polyglot) getimagesize/finfo eşleşmesinden düşer; yol geçişi adı sunucu adını etkilemez; public diske dosya yazılmaz.
        $pngHead = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
        foreach ([
            ['kabuk.php.jpg', '<?php system($_GET["c"]); ?>'],
            ['kabuk.jpg', 'GIF89a<?php echo shell_exec($_GET["c"]); ?>'],
            ['sayfa.png', '<html><body><script>alert(1)</script></body></html>'],
            ['vektor.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script></svg>'],
            ['betik.js', 'alert(1)'],
            ['zararli.exe', "MZ\x90\x00\x03"],
            ['../../gecis.png', 'x'],
        ] as [$name, $body]) {
            $this->actingAs($admin)->from('/panel/icerik/medya')->post('/panel/icerik/medya', ['file' => UploadedFile::fake()->createWithContent($name, $body)])->assertSessionHasErrors('file');
        }
        $this->assertSame(0, Media::count());
        $this->assertSame([], Storage::disk('public')->allFiles());

        // Polyglot (geçerli PNG + PHP kuyruğu): görsel olarak geçer ama her görsel GD ile yeniden kodlandığından kuyruk
        // diske asla yazılmaz — ne ana dosyada ne varyantlarda.
        $this->actingAs($admin)->post('/panel/icerik/medya', ['file' => UploadedFile::fake()->createWithContent('poly.png', $pngHead.'<?php system($_GET["c"]); ?>')])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotSame([], Storage::disk('public')->allFiles());
        foreach (Storage::disk('public')->allFiles() as $stored) {
            $this->assertStringNotContainsString('<?php', (string) Storage::disk('public')->get($stored), $stored);
        }
        Media::query()->delete();
        Storage::fake('public');

        // Tarayıcı erişilemez: yükleme REDDEDİLİR (fail-closed).
        $this->scanner->result = ScanResult::unavailable('clamd yok');
        $this->actingAs($admin)->from('/panel/icerik/medya')->post('/panel/icerik/medya', ['file' => $this->png()])->assertSessionHasErrors('file');
        $this->assertSame(0, Media::count());

        // Enfekte: reddedilir, yazılmaz.
        $this->scanner->result = ScanResult::infected('Eicar-Test-Signature');
        $this->actingAs($admin)->from('/panel/icerik/medya')->post('/panel/icerik/medya', ['file' => $this->png()])->assertSessionHasErrors('file');
        $this->assertSame(0, Media::count());
        $this->assertSame([], Storage::disk('public')->allFiles());

        // Temiz: yüklenir. Yabancı sitenin görseli kapak olamaz; başka site bağlamında 404.
        $this->scanner->result = ScanResult::clean();
        $this->actingAs($ops)->post('/panel/icerik/medya', ['file' => $this->png()])->assertRedirect()->assertSessionHasNoErrors();
        $media = Media::firstOrFail();

        $tenant = Website::create(['organization_id' => $this->organization('Acme')->id, 'name' => 'Acme', 'slug' => 'acme', 'domain' => 'acme.example']);
        $this->actingAs($admin)->from('/panel/icerik/yeni')->post('/panel/icerik', ['website_id' => $tenant->id, 'kind' => 'page', 'title' => 'Acme sayfa', 'body' => 'x', 'cover_media_id' => $media->id])->assertSessionHasErrors();
        $this->assertNull(Content::where('title', 'Acme sayfa')->first());
        $this->actingAs($admin)->delete("/panel/icerik/medya/{$media->id}?website={$tenant->id}")->assertNotFound();
        $this->actingAs($admin)->put("/panel/websiteler/{$tenant->id}/hero", ['hero_media_id' => $media->id])->assertNotFound();

        // ops silemez (content.publish yok); müşteri sahibi medya ekranına giremez.
        $this->actingAs($ops)->delete("/panel/icerik/medya/{$media->id}")->assertForbidden();
        $this->actingAs($this->owner($this->organization('Beta')))->get('/panel/icerik/medya')->assertForbidden();
    }
}
