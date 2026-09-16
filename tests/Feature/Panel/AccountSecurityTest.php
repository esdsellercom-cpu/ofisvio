<?php

namespace Tests\Feature\Panel;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Faz 5 — hesap sayfası, şifre değişimi, 2FA (TOTP) kurulum ve giriş.
 */
class AccountSecurityTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    /** Şifre onayı yakın zamanda alınmış say (password.confirm middleware'i). */
    private function passwordConfirmed(): static
    {
        return $this->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function currentOtp(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp(decrypt($user->fresh()->two_factor_secret));
    }

    #[Test]
    public function hesap_sayfasi_profil_ve_sifre_gunceller(): void
    {
        $user = User::factory()->create(['password' => 'eski-sifre-1234']);

        $this->actingAs($user)->get('/panel/hesap')->assertOk()->assertSee('Profil')->assertSee('Kapalı');

        $this->actingAs($user)->from('/panel/hesap')
            ->put('/user/profile-information', ['name' => 'Yeni Ad', 'email' => 'yeni@example.com'])
            ->assertRedirect('/panel/hesap')
            ->assertSessionHas('status', 'profile-information-updated');

        $this->assertSame('yeni@example.com', $user->fresh()->email);

        $this->actingAs($user)->from('/panel/hesap')
            ->put('/user/password', [
                'current_password' => 'eski-sifre-1234',
                'password' => 'yeni-sifre-5678',
                'password_confirmation' => 'yeni-sifre-5678',
            ])
            ->assertRedirect('/panel/hesap')
            ->assertSessionHas('status', 'password-updated');

        $this->assertTrue(password_verify('yeni-sifre-5678', $user->fresh()->password));

        // Layout Fortify anahtarını Türkçeye çevirir.
        $this->actingAs($user)->withSession(['status' => 'password-updated'])
            ->get('/panel/hesap')->assertSee('Şifreniz güncellendi.');
    }

    #[Test]
    public function guvenlik_sayfasi_sifre_onayi_ister(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/panel/hesap/guvenlik')->assertRedirect('/user/confirm-password');
        $this->actingAs($user)->get('/user/confirm-password')->assertOk()->assertSee('şifrenizi doğrulayın');

        $this->actingAs($user)->passwordConfirmed()->get('/panel/hesap/guvenlik')
            ->assertOk()
            ->assertSee("2FA'yı etkinleştir", false);
    }

    #[Test]
    public function iki_adimli_dogrulama_kurulur_dogrulanir_ve_giriste_istenir(): void
    {
        $user = User::factory()->create(['password' => 'gizli-sifre-123']);

        // Etkinleştir -> gizli anahtar var, henüz doğrulanmadı.
        $this->actingAs($user)->passwordConfirmed()->from('/panel/hesap/guvenlik')
            ->post('/user/two-factor-authentication')
            ->assertRedirect('/panel/hesap/guvenlik')
            ->assertSessionHas('status', 'two-factor-authentication-enabled');

        $this->assertNotNull($user->fresh()->two_factor_secret);
        $this->assertFalse($user->fresh()->hasConfirmedTwoFactor());

        $this->actingAs($user)->passwordConfirmed()->get('/panel/hesap/guvenlik')
            ->assertOk()
            ->assertSee('Doğrulama bekliyor')
            ->assertSee('<svg', false); // QR

        // Yanlış kod reddedilir.
        $this->actingAs($user)->passwordConfirmed()->from('/panel/hesap/guvenlik')
            ->post('/user/confirmed-two-factor-authentication', ['code' => '000000'])
            ->assertSessionHasErrors(['code'], null, 'confirmTwoFactorAuthentication');

        // Doğru kod: etkin, kurtarma kodları görünür.
        $this->actingAs($user)->passwordConfirmed()->from('/panel/hesap/guvenlik')
            ->post('/user/confirmed-two-factor-authentication', ['code' => $this->currentOtp($user)])
            ->assertRedirect('/panel/hesap/guvenlik')
            ->assertSessionHas('status', 'two-factor-authentication-confirmed');

        $this->assertTrue($user->fresh()->hasConfirmedTwoFactor());
        $codes = $user->fresh()->recoveryCodes();
        $this->assertCount(8, $codes);

        $this->actingAs($user)->passwordConfirmed()->get('/panel/hesap/guvenlik')
            ->assertSee('Kurtarma kodları')
            ->assertSee($codes[0]);

        // Giriş artık iki adımlı.
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'gizli-sifre-123'])
            ->assertRedirect('/two-factor-challenge');
        $this->assertGuest();

        $this->get('/two-factor-challenge')->assertOk()->assertSee('İki adımlı doğrulama');

        $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();

        // Replay koruması: onayda kullanılan kod aynı pencerede yeniden kabul edilmez.
        $this->post('/two-factor-challenge', ['code' => $this->currentOtp($user)])->assertSessionHasErrors('code');
        $this->assertGuest();

        Cache::flush(); // yeni zaman penceresini simüle et
        $this->post('/two-factor-challenge', ['code' => $this->currentOtp($user)])->assertRedirect('/panel');
        $this->assertAuthenticatedAs($user);

        // Kurtarma koduyla giriş: kod tek kullanımlık.
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'gizli-sifre-123']);
        $this->post('/two-factor-challenge', ['recovery_code' => $codes[0]])->assertRedirect('/panel');
        $this->assertAuthenticatedAs($user);
        $this->assertNotContains($codes[0], $user->fresh()->recoveryCodes());
    }

    #[Test]
    public function personel_hesabinda_2fa_kapaliysa_uyari_gorur(): void
    {
        $admin = $this->staff('system_admin');

        $this->actingAs($admin)->get('/panel/hesap')
            ->assertOk()
            ->assertSee('Personel hesabınızda iki adımlı doğrulama kapalı');

        $customer = User::factory()->create();
        $this->actingAs($customer)->get('/panel/hesap')
            ->assertOk()
            ->assertDontSee('Personel hesabınızda');
    }

    #[Test]
    public function iki_adimli_dogrulama_kapatilabilir(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->passwordConfirmed()->post('/user/two-factor-authentication');
        $this->actingAs($user)->passwordConfirmed()->post('/user/confirmed-two-factor-authentication', ['code' => $this->currentOtp($user)]);
        $this->assertTrue($user->fresh()->hasConfirmedTwoFactor());

        $this->actingAs($user)->passwordConfirmed()->from('/panel/hesap/guvenlik')
            ->delete('/user/two-factor-authentication')
            ->assertRedirect('/panel/hesap/guvenlik')
            ->assertSessionHas('status', 'two-factor-authentication-disabled');

        $this->assertNull($user->fresh()->two_factor_secret);
    }
}
