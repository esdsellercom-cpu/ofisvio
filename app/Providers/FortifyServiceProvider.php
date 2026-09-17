<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

/**
 * Auth — zincirin 1. halkası (Authentication).
 *
 * Fortify yalnızca kimliği doğrular; "hangi organizasyon" sorusu buradan
 * SONRA, EnsureTenantContext'te cevaplanır. Bu yüzden giriş sonrası hedef
 * config('fortify.home') = /panel'dir: panel route'u tenant middleware'i
 * taşır ve context yoksa kullanıcıyı organizasyon seçimine yönlendirir.
 *
 * Kayıt (registration) kapalıdır — bkz. config/fortify.php.
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        Fortify::loginView(fn () => view('auth.login'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));
        Fortify::verifyEmailView(fn () => view('auth.verify-email'));

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by((string) $request->session()->get('login.id'));
        });

        // Şifre sıfırlama e-postası aynı zamanda DAVET e-postasıdır (bkz.
        // OrganizationOnboardingService): metin iki durumu da karşılar.
        ResetPassword::toMailUsing(function (object $notifiable, string $token): MailMessage {
            $url = url(route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false));

            $minutes = (int) config('auth.passwords.'.config('fortify.passwords').'.expire', 60);

            return (new MailMessage)
                ->subject(config('app.name').' — şifrenizi belirleyin')
                ->greeting('Merhaba,')
                ->line('Aşağıdaki bağlantıyla '.config('app.name').' panel hesabınızın şifresini belirleyebilirsiniz.')
                ->action('Şifremi belirle', $url)
                ->line("Bağlantı {$minutes} dakika geçerlidir. Süresi dolarsa giriş sayfasındaki \"Şifremi unuttum\" ile yenisini isteyebilirsiniz.")
                ->line('Bu isteği siz yapmadıysanız bir şey yapmanız gerekmez.')
                ->salutation(config('app.name'));
        });

        // Kaba kuvvet savunması: e-posta + IP başına dakikada 5 deneme.
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
