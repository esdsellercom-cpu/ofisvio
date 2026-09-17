<?php

namespace App\Integrations;

use App\Console\Commands\DoctorCommand;
use App\Models\IntegrationLog;
use App\Security\MalwareScanner;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * "Bağlantıyı test et" (faz 52): her bağlantı için gerçek, yan etkisiz bir yoklama. Sağlayıcılar Integration
 * Gateway üzerinden (SSRF koruması, zaman aşımı, IntegrationLog); çekirdek bağlantılar yerel yoklama. Sonuç
 * ok | warn | fail + kısa not; secret değeri hiçbir zaman döndürülmez/loglanmaz.
 */
class ConnectionTester
{
    public function __construct(private readonly Gateway $gateway, private readonly SecretStore $secrets, private readonly MalwareScanner $scanner) {}

    /** @return array{level: string, note: string} */
    public function test(string $key): array
    {
        if (isset(IntegrationCatalog::CORE[$key])) {
            return $this->core($key);
        }

        if (is_array(config("integrations.providers.{$key}"))) {
            return $this->provider($key);
        }

        return ['level' => 'fail', 'note' => 'Tanımsız bağlantı: '.$key];
    }

    /** @return array{level: string, note: string} */
    private function provider(string $key): array
    {
        if (! $this->secrets->enabled($key)) {
            return ['level' => 'warn', 'note' => 'Kapalı (env ile açılır: '.strtoupper($key).'_ENABLED=true).'];
        }

        $missing = $this->secrets->missing($key);

        if ($missing !== []) {
            return ['level' => 'fail', 'note' => 'Eksik secret: '.implode(', ', $missing)];
        }

        try {
            $response = $this->gateway->request($key, 'GET', '/');
            $status = $response->status();

            return $status < 500
                ? ['level' => 'ok', 'note' => 'Erişilebilir (HTTP '.$status.')']
                : ['level' => 'fail', 'note' => 'Sunucu hatası (HTTP '.$status.')'];
        } catch (Throwable $e) {
            return ['level' => 'fail', 'note' => mb_substr($e->getMessage(), 0, 200)];
        }
    }

    /** @return array{level: string, note: string} */
    private function core(string $key): array
    {
        try {
            return match ($key) {
                'database' => $this->probe(fn () => DB::select('select 1') !== [] ? 'Bağlı ('.config('database.default').')' : 'Sorgu boş döndü'),
                'cache' => $this->probe(function () {
                    $k = 'health:'.Str::random(8);
                    Cache::put($k, 'ok', 60);
                    $ok = Cache::get($k) === 'ok';
                    Cache::forget($k);

                    return $ok ? 'Yazıldı/okundu ('.config('cache.default').')' : throw new RuntimeException('Önbellek yazılamıyor');
                }),
                'queue' => (string) config('queue.default') === 'sync'
                    ? ['level' => 'warn', 'note' => 'sync — işler istek içinde koşar; üretimde worker kurun']
                    : $this->probe(fn () => config('queue.default').' · bekleyen '.DB::table('jobs')->count().' · başarısız '.DB::table('failed_jobs')->count()),
                'scheduler' => $this->scheduler(),
                'mail' => $this->mail(),
                'storage' => $this->probe(function () {
                    $out = [];

                    foreach (['private', 'local', 'public'] as $disk) {
                        $p = 'health/'.Str::random(8).'.txt';
                        $d = Storage::disk($disk);
                        $ok = $d->put($p, 'ok') && $d->get($p) === 'ok';
                        $d->delete($p);
                        $out[] = $disk.($ok ? ' ✓' : ' ✗');

                        if (! $ok) {
                            throw new RuntimeException('Disk yazılamıyor: '.$disk);
                        }
                    }

                    return implode(' · ', $out);
                }),
                'scanner' => $this->scanner(),
                'webhooks' => ['level' => 'ok', 'note' => 'POST /webhooks/{provider} — imza doğrulamalı; tolerans '.config('integrations.webhook_tolerance_seconds').' sn'],
                default => ['level' => 'fail', 'note' => 'Tanımsız'],
            };
        } catch (Throwable $e) {
            return ['level' => 'fail', 'note' => mb_substr($e->getMessage(), 0, 200)];
        }
    }

    /** @return array{level: string, note: string} */
    private function scheduler(): array
    {
        $last = Cache::get(DoctorCommand::HEARTBEAT_KEY);

        if ($last === null) {
            return ['level' => 'fail', 'note' => 'Henüz çalışmadı — cron: * * * * * php artisan schedule:run'];
        }

        $minutes = (int) now()->diffInMinutes($last, true);

        return $minutes > DoctorCommand::HEARTBEAT_MAX_MINUTES ? ['level' => 'fail', 'note' => "Son çalışma {$minutes} dk önce"] : ['level' => 'ok', 'note' => "Son çalışma {$minutes} dk önce"];
    }

    /** @return array{level: string, note: string} */
    private function mail(): array
    {
        $mailer = (string) config('mail.default');

        if (in_array($mailer, ['log', 'array'], true)) {
            return ['level' => 'warn', 'note' => $mailer.' — gerçek gönderim yok (davet/şifre e-postaları gitmez)'];
        }

        if ($mailer !== 'smtp') {
            return ['level' => 'ok', 'note' => $mailer.' (sağlayıcı taşıyıcısı; gönderim sırasında doğrulanır)'];
        }

        $host = (string) config('mail.mailers.smtp.host');
        $port = (int) config('mail.mailers.smtp.port', 587);
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, 4);

        if ($socket === false) {
            return ['level' => 'fail', 'note' => "SMTP {$host}:{$port} erişilemez ({$errstr})"];
        }

        fclose($socket);

        return ['level' => 'ok', 'note' => "SMTP {$host}:{$port} erişilebilir; kimlik gönderimde doğrulanır"];
    }

    /** @return array{level: string, note: string} */
    private function scanner(): array
    {
        $driver = (string) config('ofisvio.kyc.scanner', 'null');

        if ($driver !== 'clamav') {
            return ['level' => 'warn', 'note' => $driver.' — üretimde clamav zorunlu (KYC_SCANNER=clamav)'];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ofisvio-health-');

        if ($tmp === false) {
            return ['level' => 'fail', 'note' => 'Geçici dosya oluşturulamadı'];
        }

        file_put_contents($tmp, 'ofisvio health probe');

        try {
            $result = $this->scanner->scan($tmp);
        } finally {
            @unlink($tmp);
        }

        return $result->available ? ['level' => 'ok', 'note' => 'clamd canlı ('.config('ofisvio.kyc.clamav.address').')'] : ['level' => 'fail', 'note' => 'clamd erişilemez'];
    }

    /**
     * @param  callable(): string  $fn
     * @return array{level: string, note: string}
     */
    private function probe(callable $fn): array
    {
        return ['level' => 'ok', 'note' => $fn()];
    }

    /** Son test kaydı (panel satırı). */
    public function last(string $key): ?IntegrationLog
    {
        return IntegrationLog::query()->where('provider', 'health:'.$key)->latest('id')->first();
    }

    /**
     * Son testler (sağlık sayfası).
     *
     * @return Collection<int, IntegrationLog>
     */
    public function recent(int $limit = 20): Collection
    {
        return IntegrationLog::query()->where('provider', 'like', 'health:%')->latest('id')->limit($limit)->get();
    }

    /** Test sonucunu entegrasyon günlüğüne yazar (kim/ne zaman izlenir). */
    public function log(string $key, array $result, int $durationMs): void
    {
        IntegrationLog::create(['provider' => 'health:'.$key, 'method' => 'TEST', 'path' => '/', 'status' => null, 'duration_ms' => $durationMs, 'ok' => $result['level'] === 'ok', 'error' => $result['level'] === 'ok' ? null : mb_substr($result['note'], 0, 250)]);
    }
}
