<?php

namespace Tests\Feature\Panel;

use App\Models\Organization;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Müşteri organizasyonu açılışı — personel yolu (user.manage, global).
 */
class OnboardingTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        Notification::fake();
    }

    #[Test]
    public function personel_organizasyon_acar_sahibi_davet_edilir(): void
    {
        $admin = $this->staff('system_admin');

        // Context YOK — bu ekran context'siz çalışmak zorunda.
        $this->actingAs($admin)->get('/panel/yeni-musteri')->assertOk()->assertSee('Organizasyon adı');

        $this->actingAs($admin)
            ->post('/panel/yeni-musteri', [
                'organization_name' => 'Örnek Holding',
                'owner_name' => 'Zeynep Aydın',
                'owner_email' => 'Zeynep@Ornek.example',
            ])
            ->assertRedirect('/panel/organizasyon')
            ->assertSessionHas('status');

        $organization = Organization::where('slug', 'ornek-holding')->firstOrFail();
        $owner = User::where('email', 'zeynep@ornek.example')->firstOrFail(); // küçük harfe çevrilir

        $this->assertTrue($organization->members()->where('user_id', $owner->id)->where('status', 'active')->exists());
        $this->assertTrue(
            UserRole::where('user_id', $owner->id)->where('organization_id', $organization->id)->exists(),
            'Sahibe organizasyon kapsamlı owner rolü atanmalı.'
        );

        Notification::assertSentTo($owner, ResetPassword::class);

        // Sahip artık organizasyonuna girebilir.
        $owner->markEmailAsVerified(); // davet e-postası → şifre belirleme adresi doğrular (audit S-4; ResetUserPassword)
        $this->actingAs($owner)->get('/panel')->assertOk()->assertSee('Örnek Holding');
    }

    #[Test]
    public function ayni_eposta_ikinci_kez_kullanici_yaratmaz_uye_yapar(): void
    {
        $admin = $this->staff('system_admin');
        $existing = User::factory()->create(['email' => 'mevcut@example.com']);

        $this->actingAs($admin)->post('/panel/yeni-musteri', [
            'organization_name' => 'İkinci Org',
            'owner_name' => 'Fark Etmez',
            'owner_email' => 'mevcut@example.com',
        ])->assertRedirect('/panel/organizasyon');

        $this->assertSame(1, User::where('email', 'mevcut@example.com')->count());
        $this->assertTrue($existing->organizationMemberships()->exists());
        Notification::assertNothingSent(); // mevcut hesaba davet gitmez
    }

    #[Test]
    public function slug_cakismasi_cozulur(): void
    {
        $admin = $this->staff('system_admin');
        Organization::create(['name' => 'Acme', 'slug' => 'acme']);

        $this->actingAs($admin)->post('/panel/yeni-musteri', [
            'organization_name' => 'Acme',
            'owner_name' => 'Ali',
            'owner_email' => 'ali@example.com',
        ])->assertRedirect('/panel/organizasyon');

        $this->assertTrue(Organization::where('slug', 'acme-2')->exists());
    }

    #[Test]
    public function musteri_organizasyon_acamaz(): void
    {
        $acme = $this->organization('Acme');
        $owner = $this->owner($acme);

        $this->actingAs($owner)->get('/panel/yeni-musteri')->assertForbidden();
        $this->actingAs($owner)->post('/panel/yeni-musteri', [
            'organization_name' => 'Kaçak', 'owner_name' => 'X', 'owner_email' => 'x@example.com',
        ])->assertForbidden();
    }
}
