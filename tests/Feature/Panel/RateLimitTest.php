<?php

namespace Tests\Feature\Panel;

use App\Providers\RateLimitServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regresyon: hız sınırları amaca özel ve AYRI sayaçlıdır. Sayısal
 * throttle:20,1 kullanıldığında tüm route'lar kullanıcı başına tek sayacı
 * paylaşıyordu — 20 belge yükleyen kullanıcı üye davet edemiyordu (CI'da
 * Redis ile 7 test 429 aldı).
 */
class RateLimitTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        Storage::fake('private');
        Notification::fake();
    }

    #[Test]
    public function yukleme_sinirinin_dolmasi_daveti_engellemez(): void
    {
        $acme = $this->organization('Acme');
        $company = $this->company($acme, 'Acme Ltd');
        $owner = $this->owner($acme, $company);

        // Yükleme kovasını GERÇEK isteklerle doldur: sınır kadar yükleme geçer,
        // bir fazlası 429 alır.
        $pdf = fn () => UploadedFile::fake()->createWithContent('v.pdf', "%PDF-1.4\n%%EOF\n");
        $limit = RateLimitServiceProvider::LIMITS['kyc-upload'];

        for ($i = 0; $i < $limit; $i++) {
            $this->actingAs($owner)->withContext($acme)
                ->post("/panel/sirketler/{$company->id}/kyc", ['type' => 'TAX_CERTIFICATE', 'file' => $pdf()])
                ->assertRedirect("/panel/sirketler/{$company->id}/kyc");
        }

        $this->actingAs($owner)->withContext($acme)
            ->post("/panel/sirketler/{$company->id}/kyc", ['type' => 'TAX_CERTIFICATE', 'file' => $pdf()])
            ->assertStatus(429);

        // Davet ayrı kovada: geçer.
        $this->actingAs($owner)->withContext($acme)
            ->post("/panel/sirketler/{$company->id}/uyeler", ['name' => 'Ali Veli', 'email' => 'ali@example.com', 'role' => 'viewer'])
            ->assertRedirect("/panel/sirketler/{$company->id}/uyeler");
    }

    #[Test]
    public function her_limiter_kayitli_ve_kullanici_bazli(): void
    {
        foreach (array_keys(RateLimitServiceProvider::LIMITS) as $name) {
            $this->assertNotNull(RateLimiter::limiter($name), "'{$name}' limiter'ı kayıtlı değil");
        }
    }
}
