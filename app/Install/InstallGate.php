<?php

namespace App\Install;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Web kurulum sihirbazının kapısı (faz 62) — middleware, doctor ve testler AYNI sınıfı okur.
 *
 * Neden kapı: /install ucu, henüz kurulmamış ve internete açık bir sunucuda yönetici hesabı açar ve .env yazar.
 * "İlk gelen kurar" modeli kabul edilemez (yeni alan adları Certificate Transparency günlüklerinden saniyeler
 * içinde taranır). Bu yüzden uç, operatör sunucuda `storage/app/install/challenge.txt` dosyasını kendi yazdığı
 * ≥32 karakterlik bir anahtarla oluşturana kadar YOKTUR (404). Dosyayı oluşturabilmek dosya sistemine erişim
 * kanıtıdır — audit F-17'nin "kurulum, sunucuya erişimi olan operatörün işidir" gerekçesi korunur, yalnız kanıt
 * biçimi shell'den dosya yöneticisine taşınır (paylaşımlı hostingde shell yoktur).
 *
 * Kapalı olmanın nedenleri dışarıdan ayırt edilemez: kilit dosyası, dolu veritabanı, eksik/süresi geçmiş anahtar
 * dosyası ve farklı IP — hepsi aynı 404. Anahtar değeri hiçbir log/audit/flash kaydına yazılmaz.
 */
final class InstallGate
{
    /** InstallCommand::LOCK ile aynı dosya: CLI ve web kurulumu tek kilidi paylaşır. */
    public const LOCK = 'app/.installed';

    public const DIRECTORY = 'app/install';

    public const CHALLENGE = 'app/install/challenge.txt';

    public const IP_PIN = 'app/install/ip.txt';

    public const STATE = 'app/install/state.json';

    public const RUNNING = 'app/install/.running';

    /**
     * Anahtar dosyası oluşturulduktan (ya da paketle birlikte geldiyse paketlendikten) sonra kurulumun
     * BAŞLATILABİLECEĞİ süre. Pencere, unutulmuş bir anahtar dosyasının süresiz açık kalmasını engeller;
     * asıl güvenlik anahtarın gizliliğindedir. Bir hafta, dosya yöneticisiyle çalışan operatör için gerçekçi
     * (bir saat değildi); kurulum bitince dosya zaten silinir, kalırsa doctor üretimde hata verir.
     */
    public const TTL_SECONDS = 604800;

    /**
     * Başlamış kurulumun penceresi. Yarıda bırakılan bir sihirbaz sonsuza dek açık kalmaz: durum dosyasına
     * dokunulmadan bu süre geçerse uç yeniden kapanır (her adım durum dosyasını tazeler).
     */
    public const STARTED_TTL_SECONDS = 86400;

    public const MIN_TOKEN_LENGTH = 32;

    public const SESSION_KEY = 'install.authorized';

    public function challengePath(): string
    {
        return storage_path(self::CHALLENGE);
    }

    public function lockPath(): string
    {
        return storage_path(self::LOCK);
    }

    public function installed(): bool
    {
        return is_file($this->lockPath());
    }

    /** Anahtar dosyası duruyor mu (doctor: kurulum bitince silinmeli). */
    public function challengeExists(): bool
    {
        return is_file($this->challengePath());
    }

    /** Kurulum ucu var mı: kilit yok + geçerli anahtar dosyası + (başlamadıysa) boş veritabanı. */
    public function open(): bool
    {
        if ($this->installed()) {
            return $this->refuse('Kurulum kilidi var: '.$this->lockPath().' — kurulum tamamlanmış sayılıyor. Yeniden kurulacaksa bu dosyayı silin.');
        }

        if (! $this->challengeValid()) {
            return false; // nedeni challengeValid() günlüğe yazar
        }

        if (! $this->started() && $this->databaseInUse()) {
            return $this->refuse('Veritabanında kullanıcı kaydı var: dolu sistem üstüne kurulum yapılmaz.');
        }

        return true;
    }

    /** Dosya var, ≥32 karakter ve (kurulum başlamadıysa) TTL içinde. */
    public function challengeValid(): bool
    {
        $path = $this->challengePath();

        if (! is_file($path)) {
            return false; // dosya hiç yoksa günlüğe yazmayız: her tarayıcı botu günlüğü şişirirdi
        }

        if (! is_readable($path)) {
            return $this->refuse('Anahtar dosyası okunamıyor (dosya izinleri): '.$path);
        }

        $length = strlen($this->token());

        if ($length < self::MIN_TOKEN_LENGTH) {
            return $this->refuse('Anahtar çok kısa: '.$length.' karakter, en az '.self::MIN_TOKEN_LENGTH.' olmalı. Dosyayı açıp daha uzun bir metin yazıp kaydedin: '.$path);
        }

        if ($this->started()) {
            return true; // başlamış kurulum yarıda TTL'e takılıp operatörü dışarıda bırakmaz (kendi penceresi var)
        }

        $age = time() - (int) filemtime($path);

        if ($age > self::TTL_SECONDS) {
            return $this->refuse('Anahtar dosyası '.(int) round($age / 3600).' saat önce kaydedilmiş, süre '.(int) round(self::TTL_SECONDS / 3600).' saat. Dosyayı açıp yeniden kaydedin (içeriği değiştirmeseniz de olur).');
        }

        return true;
    }

    public function verify(string $input, string $ip): bool
    {
        if (! $this->challengeValid() || ! hash_equals($this->token(), trim($input))) {
            return false;
        }

        $this->pinIp($ip);
        $this->putState(['started_at' => date('c')] + $this->state());

        return true;
    }

    /** İlk doğru anahtarla gelen IP çivilenir; sonraki istekler aynı adresten gelmeli. */
    public function ipAllowed(string $ip): bool
    {
        $path = storage_path(self::IP_PIN);

        if (! is_file($path)) {
            return true;
        }

        return hash_equals(trim((string) file_get_contents($path)), $ip);
    }

    /**
     * Kurulum başlamış ve penceresi açık mı. Pencere durum dosyasının SON DEĞİŞME zamanından ölçülür: her adım
     * durumu tazeler, terk edilen kurulum kendiliğinden kapanır (aksi halde dolu veritabanı denetimi de kalkardı).
     */
    public function started(): bool
    {
        $path = storage_path(self::STATE);

        if (! is_file($path) || ($this->state()['started_at'] ?? null) === null) {
            return false;
        }

        return (time() - (int) filemtime($path)) <= self::STARTED_TTL_SECONDS;
    }

    /**
     * Bu kurulumda YAŞAYAN bir sistem var mı: kullanıcı kaydı. (Hedef veritabanının temizliği ayrıca
     * InstallWizardService::assertEmptyDatabase ile denetlenir; orada migrations tablosu da sayılır.)
     */
    public function databaseInUse(): bool
    {
        try {
            return Schema::hasTable('users') && DB::table('users')->limit(1)->count() > 0;
        } catch (Throwable) {
            return false; // veritabanı erişilemiyor: "kurulu" diyemeyiz, kurulum zaten DB adımında duracak
        }
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        $path = storage_path(self::STATE);

        if (! is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    public function stepDone(string $step): bool
    {
        return (bool) ($this->state()['steps'][$step] ?? false);
    }

    public function markStep(string $step): void
    {
        // Oku-değiştir-yaz tek kilit altında: eşzamanlı iki adım durumu birbirini ezerse kurulum kurtarılamaz
        // biçimde 404'e düşerdi (bozuk durum = "başlamamış" + dolu veritabanı).
        $lock = storage_path(self::DIRECTORY.'/.state.lock');
        File::ensureDirectoryExists(dirname($lock));
        $handle = @fopen($lock, 'c');

        try {
            if ($handle !== false) {
                flock($handle, LOCK_EX);
            }

            $state = $this->state();
            $state['steps'][$step] = true;
            $this->putState($state);
        } finally {
            if ($handle !== false) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    /** @param  array<string, mixed>  $values */
    public function remember(array $values): void
    {
        $this->putState($values + $this->state());
    }

    /**
     * Kurulumu kapat: kilit yazılır, kurulum klasörü (anahtar, IP çivisi, durum, .env yedeği) SİLİNİR.
     *
     * @return array<int, string> silinemeyen dosyalar — çağıran bunları operatöre gösterir (doctor da üretimde hata verir)
     */
    public function complete(): array
    {
        File::ensureDirectoryExists(dirname($this->lockPath()));
        File::put($this->lockPath(), date('c').' '.(string) config('app.version', '').' '.(string) config('app.url')."\n");

        File::deleteDirectory(storage_path(self::DIRECTORY));

        $leftovers = [];

        foreach ([self::CHALLENGE, self::IP_PIN, self::STATE] as $file) {
            if (is_file(storage_path($file))) {
                $leftovers[] = storage_path($file);
            }
        }

        return $leftovers;
    }

    /**
     * Kapının reddetme nedenini OPERATÖRE bildirir: `storage/logs/install.log` (web'den erişilemez, dosya
     * yöneticisinden okunur). Ekrana hiçbir şey yazılmaz — dışarıya sızan tek şey yine 404'tür. Anahtarın
     * değeri değil, yalnız uzunluğu/yaşı yazılır. Aynı neden dakikada bir kez yazılır (günlük şişmesin).
     */
    private function refuse(string $reason): bool
    {
        $path = storage_path('logs/install.log');

        try {
            if (is_file($path) && (time() - (int) filemtime($path)) < 60 && str_contains((string) file_get_contents($path), $reason)) {
                return false;
            }

            File::ensureDirectoryExists(dirname($path));
            File::append($path, date('c').' KURULUM KAPISI REDDETTİ — '.$reason.PHP_EOL);
        } catch (Throwable) {
            // günlük yazılamıyorsa kapı kararı değişmez
        }

        return false;
    }

    private function token(): string
    {
        return trim((string) file_get_contents($this->challengePath()));
    }

    private function pinIp(string $ip): void
    {
        $path = storage_path(self::IP_PIN);

        if (! is_file($path)) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $ip."\n");
        }
    }

    /**
     * Durum dosyası atomik yazılır ve asla bozuk bırakılmaz: yarım/geçersiz JSON, kurulumu kurtarılamaz biçimde
     * kapatırdı (bozuk durum = "başlamamış" sayılır, veritabanı ise artık dolu).
     *
     * @param  array<string, mixed>  $state
     */
    private function putState(array $state): void
    {
        $path = storage_path(self::STATE);
        File::ensureDirectoryExists(dirname($path));
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if ($json === false) {
            return; // kodlanamayan durum yazılmaz; var olan sağlam dosya korunur
        }

        $tmp = $path.'.tmp';
        File::put($tmp, $json);

        // rename() atomiktir; Windows'ta hedef varken başarısız olur — o durumda doğrudan yazılır.
        if (! @rename($tmp, $path)) {
            File::put($path, $json);
            File::delete($tmp);
        }
    }
}
