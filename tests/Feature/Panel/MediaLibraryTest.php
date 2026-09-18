<?php

namespace Tests\Feature\Panel;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Media;
use App\Models\Website;
use App\Security\MalwareScanner;
use App\Security\ScanResult;
use App\Services\ContentCache;
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
        $this->get('/blog/kapakli-yazi')->assertOk()->assertSee('<meta property="og:image" content="'.$media->url().'">', false)->assertSee('alt="Levent şubesi"', false);

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
