<?php

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureInstallable;
use App\Install\InstallGate;
use App\Install\InstallWizardService;
use App\Install\PasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Web kurulum sihirbazı (faz 62) — shell erişimi olmayan operatör için.
 *
 * Zincir: anahtar (dosya sistemi kanıtı) → gereksinimler → veritabanı → site → tablolar → referans veri →
 * yönetici → bitir. Her ağır adım kendi POST'unda (paylaşımlı hostingde tek istekte zaman aşımına uğrardı).
 * Kapı EnsureInstallable middleware'inde; controller DB sorgusu yapmaz, iş InstallWizardService'tedir.
 */
class InstallController extends Controller
{
    public function __construct(private readonly InstallGate $gate, private readonly InstallWizardService $wizard) {}

    public function token(): View
    {
        return view('install.token');
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:'.InstallGate::MIN_TOKEN_LENGTH, 'max:255'],
        ], [], ['token' => 'kurulum anahtarı']);

        if (! $this->gate->verify($data['token'], EnsureInstallable::clientAddress($request))) {
            // Neden ayırt edilmez: yanlış anahtar da süresi geçmiş dosya da aynı mesajı alır.
            return back()->withErrors(['token' => 'Kurulum anahtarı doğrulanmadı. storage/app/install/challenge.txt içeriğini olduğu gibi yapıştırın; dosyayı 1 saatten önce oluşturduysanız kaydedip yeniden deneyin.']);
        }

        $request->session()->regenerate(); // oturum sabitlemesine karşı: yetki bayrağı yeni kimliğe yazılır
        $request->session()->put(InstallGate::SESSION_KEY, true);

        return redirect()->route('install.requirements');
    }

    public function requirements(): View
    {
        return view('install.requirements', $this->wizard->requirements());
    }

    public function database(): View
    {
        return view('install.database');
    }

    public function storeDatabase(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'connection' => ['required', 'in:mysql,pgsql,sqlite'],
            // Host/veritabanı adında ';' ve '=' kabul edilmez: DSN'e ek parametre enjekte edilebilirdi.
            'host' => ['required_unless:connection,sqlite', 'nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9._\-\[\]:]+$/'],
            'port' => ['required_unless:connection,sqlite', 'nullable', 'digits_between:1,5'],
            'database' => ['required', 'string', 'max:190'],
            'username' => ['required_unless:connection,sqlite', 'nullable', 'string', 'max:190', 'regex:/^[^;=\r\n]+$/'],
            'password' => ['nullable', 'string', 'max:190'],
        ]);

        $this->wizard->saveDatabase($data);

        return redirect()->route('install.site');
    }

    public function site(Request $request): View
    {
        return view('install.site', ['suggestedUrl' => $request->getSchemeAndHttpHost()]);
    }

    public function storeSite(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url:http,https', 'max:190'],
            'timezone' => ['required', 'timezone'],
            'trusted_proxies' => ['nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9.,:\/\*\-]+$/'],
            'installation_id' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9_-]+$/'],
            'mail_mailer' => ['required', 'in:log,smtp'],
            'mail_host' => ['required_if:mail_mailer,smtp', 'nullable', 'string', 'max:190'],
            'mail_port' => ['required_if:mail_mailer,smtp', 'nullable', 'digits_between:1,5'],
            'mail_username' => ['nullable', 'string', 'max:190'],
            'mail_password' => ['nullable', 'string', 'max:190'],
            'mail_from' => ['required_if:mail_mailer,smtp', 'nullable', 'email', 'max:190'],
        ]);

        $this->wizard->saveSite($data, $request->isSecure());

        return redirect()->route('install.setup');
    }

    public function setup(): View
    {
        return view('install.setup', [
            'migrated' => $this->gate->stepDone('migrate'),
            'seeded' => $this->gate->stepDone('seed'),
        ]);
    }

    public function runMigrate(): RedirectResponse
    {
        $this->wizard->migrate();

        return redirect()->route('install.setup')->with('install_notice', 'Veritabanı tabloları oluşturuldu.');
    }

    public function runSeed(): RedirectResponse
    {
        $this->wizard->seed();

        return redirect()->route('install.setup')->with('install_notice', 'Referans veri yüklendi (roller, izinler, hizmet kataloğu, varsayılan site).');
    }

    public function admin(Request $request): View
    {
        return view('install.admin', [
            'secure' => $request->isSecure(),
            'policy' => PasswordPolicy::DESCRIPTION,
            'ready' => $this->gate->stepDone('migrate') && $this->gate->stepDone('seed'),
        ]);
    }

    public function storeAdmin(Request $request): RedirectResponse
    {
        // Şifre yalnız HTTPS üzerinden alınır; ters proxy arkasında TRUSTED_PROXIES bir önceki adımda sorulur.
        if (! $request->isSecure()) {
            return back()->withErrors(['domain' => 'Yönetici şifresi yalnızca HTTPS bağlantısında alınır. SSL sertifikanızı etkinleştirin; ters proxy arkasındaysanız önceki adımdaki "Ters proxy" alanını doldurun.']);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'confirmed', 'max:190'],
        ]);

        $this->wizard->createAdmin($data);

        return redirect()->route('install.finish');
    }

    public function finish(): View
    {
        return view('install.finish', ['ready' => $this->gate->stepDone('admin')]);
    }

    /**
     * Kapanış adımı: kilidi yazar, anahtar dosyasını siler. Bundan sonra /install 404 olduğu için yönlendirme
     * yapılmaz — özet AYNI yanıtta basılır.
     */
    public function complete(): View
    {
        abort_unless($this->gate->stepDone('admin'), 404);

        return view('install.done', $this->wizard->finalize());
    }
}
