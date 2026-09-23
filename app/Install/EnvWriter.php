<?php

namespace App\Install;

use DomainException;
use Illuminate\Support\Facades\File;

/**
 * .env yazıcı (faz 62) — kurulum sihirbazının TEK yazma yüzeyi.
 *
 * Kurallar: yalnız allowlist'teki anahtarlar yazılır (serbest anahtar = yapılandırma enjeksiyonu); değerde
 * satır sonu/NUL ve phpdotenv enterpolasyonu (`${...}`) reddedilir; yazımdan önce `.env.backup` alınır; dosya
 * geçici dosyaya yazılıp yerine taşınır (yarım dosya kalmasın) ve izinleri daraltılır.
 */
final class EnvWriter
{
    /** Sihirbazın yazmasına izin verilen anahtarlar — dışına çıkılamaz. */
    public const ALLOWED = [
        'APP_ENV', 'APP_KEY', 'APP_DEBUG', 'APP_URL', 'APP_TIMEZONE',
        'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
        'SESSION_DRIVER', 'SESSION_SECURE_COOKIE', 'CACHE_STORE', 'QUEUE_CONNECTION',
        'MAIL_MAILER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_SCHEME', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME',
        'TRUSTED_PROXIES', 'OFISVIO_INSTALLATION_ID', 'KYC_SCANNER', 'CLAMAV_ADDRESS',
    ];

    public function path(): string
    {
        // Üretimde daima uygulama kökü; testte geçici dosyaya alınır (gerçek .env'e yazılmasın).
        $configured = (string) config('ofisvio.install.env_file', '');

        return $configured !== '' ? $configured : base_path('.env');
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    public function writable(): bool
    {
        return $this->exists() ? is_writable($this->path()) : is_writable(base_path());
    }

    /** .env yoksa .env.example'dan üretir; örnek de yoksa hata. */
    public function ensureExists(): void
    {
        if ($this->exists()) {
            return;
        }

        $example = base_path('.env.example');

        if (! is_file($example)) {
            throw new DomainException('.env ve .env.example bulunamadı; paket eksik açılmış.');
        }

        if (! is_writable(base_path())) {
            throw new DomainException('.env oluşturulamıyor: uygulama kökü yazılabilir değil.');
        }

        File::copy($example, $this->path());
        @chmod($this->path(), 0600);

        // Örnek dosya geliştirme içindir (APP_DEBUG=true): kopya daha ilk anda üretim değeriyle doğar; aksi halde
        // .env'in var olduğu ama kurulumun bitmediği pencerede her hata sayfası .env değerlerini basardı.
        $this->write(['APP_DEBUG' => 'false']);
    }

    /** @param  array<string, string>  $values */
    public function write(array $values): void
    {
        if ($values === []) {
            return;
        }

        $this->ensureExists();

        if (! is_writable($this->path())) {
            throw new DomainException('.env yazılamıyor: dosya izinlerini kontrol edin (644 ya da 640).');
        }

        $contents = (string) file_get_contents($this->path());
        $this->backup($contents);

        foreach ($values as $key => $value) {
            if (! in_array($key, self::ALLOWED, true)) {
                throw new DomainException('Bu anahtar kurulum sihirbazından yazılamaz: '.$key);
            }

            $contents = $this->replace($contents, $key, $this->quote($key, $value));
        }

        $tmp = $this->path().'.tmp';
        File::put($tmp, $contents);
        @chmod($tmp, 0600);

        // rename() aynı dosya sisteminde atomiktir; Windows'ta hedef varken başarısız olur — o durumda doğrudan yazılır.
        if (! @rename($tmp, $this->path())) {
            File::put($this->path(), $contents);
            File::delete($tmp);
        }

        @chmod($this->path(), 0600); // sırlar: yalnız uygulama kullanıcısı okusun (rename yolu da dahil)
    }

    /** Yedek sırları taşır: web kökünün dışına, dar izinle; kurulum kapanışında klasörle birlikte silinir. */
    private function backup(string $contents): void
    {
        $path = storage_path(InstallGate::DIRECTORY.'/env-backup.txt');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);
        @chmod($path, 0600);
    }

    private function replace(string $contents, string $key, string $line): string
    {
        $pattern = '/^[ \t]*#?[ \t]*(export[ \t]+)?'.preg_quote($key, '/').'[ \t]*=.*$/m';

        if (preg_match($pattern, $contents) === 1) {
            // preg_replace ikame dizgisi $1 / \1 kaçışlarını yorumlar: şifrede $1 varsa sessizce bozulurdu.
            return (string) preg_replace_callback($pattern, fn () => $line, $contents, 1);
        }

        return rtrim($contents, "\r\n")."\n".$line."\n";
    }

    private function quote(string $key, string $value): string
    {
        if (preg_match('/[\r\n\x00]/', $value) === 1 || str_contains($value, '${')) {
            throw new DomainException($key.' değeri geçersiz karakter içeriyor.');
        }

        $needsQuotes = $value === '' ? false : preg_match('/[\s#"\'$]/', $value) === 1;

        $slash = chr(92); // kaynakta ters eğik çizgi kaçışı yerine kod noktası: değer çift tırnaklanırken " ve \ kaçırılır
        $escaped = str_replace([$slash, '"'], [$slash.$slash, $slash.'"'], $value);

        return $key.'='.($needsQuotes ? '"'.$escaped.'"' : $value);
    }
}
