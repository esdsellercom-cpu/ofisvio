<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sistem alarmı (audit F-18): zamanlayıcı 15 dakikada bir doctor'u çalıştırır; HATA satırı ve/veya failed_jobs
 * birikimi varsa Bildirim Merkezi `system.alert` olayını üretir (varsayılan: süper yöneticilere uygulama içi + e-posta).
 * Aynı kontrol ALERT_TTL boyunca tekrar bildirilmez (gürültü yok); düzelince damga düşer, yeniden bozulursa yeniden uyarır.
 * Bildirim gönderilemezse bile komut başarılı döner (alarm sistemi uygulamayı düşürmez).
 */
class HealthAlertCommand extends Command
{
    public const ALERT_TTL_HOURS = 6;

    public const FAILED_JOBS_THRESHOLD = 1;

    protected $signature = 'ofisvio:health-alert';

    protected $description = 'Doctor hataları ve başarısız kuyruk işleri için yöneticilere sistem uyarısı üretir.';

    public function handle(NotificationService $notifications): int
    {
        Artisan::call('ofisvio:doctor', ['--json' => true]);
        $report = json_decode(Artisan::output(), true);
        $failing = [];

        foreach ((array) ($report['rows'] ?? []) as $row) {
            if (($row['level'] ?? '') === 'fail') {
                $failing[(string) $row['name']] = (string) ($row['note'] ?? '');
            }
        }

        if (Schema::hasTable('failed_jobs')) {
            $failed = DB::table('failed_jobs')->count();

            if ($failed >= self::FAILED_JOBS_THRESHOLD) {
                $failing['Başarısız kuyruk işleri'] = $failed.' iş failed_jobs tablosunda — php artisan queue:failed / queue:retry';
            }
        }

        // Düzelen kontrollerin damgası düşer; yeni ya da süresi dolmuş damgalar bildirilir.
        $fresh = [];

        foreach ($failing as $name => $note) {
            $key = 'ofisvio:alert:'.sha1($name);

            if (Cache::add($key, now()->toIso8601String(), now()->addHours(self::ALERT_TTL_HOURS))) {
                $fresh[$name] = $note;
            }
        }

        if ($fresh === []) {
            $this->line($failing === [] ? 'Sistem sağlıklı.' : count($failing).' hata sürüyor; yeni uyarı yok.');

            return self::SUCCESS;
        }

        $lines = [];

        foreach ($fresh as $name => $note) {
            $lines[] = '• '.$name.($note !== '' ? ': '.$note : '');
        }

        try {
            $notifications->dispatch('system.alert', ['message' => implode("\n", $lines), 'count' => (string) count($failing)], null, 'system', null);
            $this->warn(count($fresh).' yeni sistem uyarısı gönderildi.');
        } catch (\Throwable $e) {
            $this->error('Uyarı gönderilemedi: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
