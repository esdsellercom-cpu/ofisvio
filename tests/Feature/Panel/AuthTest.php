<?php

namespace Tests\Feature\Panel;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Zincirin 1. halkası: Authentication (Fortify).
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function giris_sayfasi_acilir(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Hesabınıza giriş yapın')
            ->assertSee('Şifremi unuttum');
    }

    #[Test]
    public function eski_giris_adresi_logine_yonlenir(): void
    {
        $this->get('/giris')->assertRedirect('/login');
    }

    #[Test]
    public function panel_oturum_ister(): void
    {
        $this->get('/panel')->assertRedirect('/login');
        $this->get('/panel/organizasyon')->assertRedirect('/login');
    }

    #[Test]
    public function dogru_bilgiyle_giris_panele_yonlenir(): void
    {
        $user = User::factory()->create(['password' => 'gizli-sifre-123']);

        $this->post('/login', ['email' => $user->email, 'password' => 'gizli-sifre-123'])
            ->assertRedirect('/panel');

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function yanlis_sifre_turkce_hata_verir(): void
    {
        $user = User::factory()->create(['password' => 'gizli-sifre-123']);

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'yanlis'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'E-posta veya şifre hatalı.']);

        $this->assertGuest();
    }

    #[Test]
    public function serbest_kayit_kapalidir(): void
    {
        // Kullanıcılar yalnızca davetle var olur (config/fortify.php).
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['email' => 'x@example.com', 'password' => 'p'])->assertNotFound();
    }

    #[Test]
    public function sifre_sifirlama_baglantisi_gonderilir(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->get('/forgot-password')->assertOk()->assertSee('Bağlantı gönderelim');

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status', 'Şifre sıfırlama bağlantısı e-posta adresinize gönderildi.');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    #[Test]
    public function cikis_oturumu_kapatir(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }
}
