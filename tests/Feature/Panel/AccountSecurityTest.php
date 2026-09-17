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

        // Yanlış kodun mesajı Türkçe ve yönlendiricidir (eski kayıt / telefon saati).
        $this->assertStringContainsString('telefonun saatinin', (string) session('errors')->getBag('confirmTwoFactorAuthentication')->first('code'));
        $this->actingAs($user)->passwordConfirmed()->get('/panel/hesap/guvenlik')->assertOk()->assertSee('daha önceki bir Ofisvio kaydı');

        // Doğru kod: etkin, kurtarma kodları görünür. Uygulamaların gösterdiği "123 456" biçimi de kabul edilir
        // (totp.normalize) ve telefon saati ±60 sn kaymış olsa da kod geçer (pencere 2).
        $otp = $this->currentOtp($user);
        $this->actingAs($user)->passwordConfirmed()->from('/panel/hesap/guvenlik')
            ->post('/user/confirmed-two-factor-authentication', ['code' => substr($otp, 0, 3).' '.substr($otp, 3)])
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
        // Telefon saati 50 sn geride: bir önceki periyodun kodu pencere (2) içinde kabul edilir.
        $previous = app(Google2FA::class)->oathTotp(decrypt($user->fresh()->two_factor_secret), app(Google2FA::class)->getTimestamp() - 2);
        $this->post('/two-factor-challenge', ['code' => $previous])->assertRedirect('/panel');
        $this->assertAuthenticatedAs($user);

        // Kurtarma koduyla giriş: kod tek kullanımlık.
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'gizli-sifre-123']);
        $this->post('/two-factor-challenge', ['recovery_code' => $codes[0]])->assertRedirect('/panel');
        $this->assertAuthenticatedAs($user);
        $this->assertNotContains($codes[0], $user->fresh()->recoveryCodes());
    }

    #[Test]
    public function personel_2fa_kurmadan_panele_giremez(): void
    {
        // Menü: 2FA kurulmadan yalnız kurulum bağlantısı görünür (Şirketler/İçerik vb. yok).
        $noTwoFactor = $this->staffWithoutTwoFactor('system_admin');
        $this->actingAs($noTwoFactor)->get('/panel/hesap')->assertOk()->assertSee('İki adımlı doğrulamayı kur')->assertDontSee('Kullanıcılar</a>', false);

        $admin = $this->staffWithoutTwoFactor('system_admin');
        $this->organization('Acme');

        // Hesap sayfası açık (kurulum burada), uyarı görünür.
        $this->actingAs($admin)->get('/panel/hesap')
            ->assertOk()
            ->assertSee('Personel hesabınızda iki adımlı doğrulama kapalı');

        // Geri kalan her şey güvenlik sayfasına yönlenir.
        foreach (['/panel', '/panel/organizasyon', '/panel/yeni-musteri'] as $url) {
            $this->actingAs($admin)->get($url)->assertRedirect('/panel/hesap/guvenlik');
        }
        $this->actingAs($admin)->post('/panel/yeni-musteri', [
            'organization_name' => 'Kaçak', 'owner_name' => 'X Y', 'owner_email' => 'x@example.com',
        ])->assertRedirect('/panel/hesap/guvenlik');

        // Müşteri kullanıcısı için zorunluluk yok.
        $customer = User::factory()->create();
        $this->actingAs($customer)->get('/panel/hesap')->assertOk()->assertDontSee('Personel hesabınızda');
        $this->actingAs($customer)->get('/panel/organizasyon')->assertOk();

        // 2FA kurulunca kapı açılır.
        $confirmed = $this->staff('system_admin');
        $this->actingAs($confirmed)->get('/panel/organizasyon')->assertOk();
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
