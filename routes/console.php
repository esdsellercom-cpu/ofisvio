<?php

use App\Models\CacheEvent;
use App\Models\LoginEvent;
use App\Models\PerformanceSample;
use App\Models\SlowQuery;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Schedule;

// Üretimde cron: * * * * * php artisan schedule:run. Her görev withoutOverlapping (aynı sunucuda çakışma yok) +
// onOneServer (çok sunuculu kurulumda tek sunucu; atomik kilit için CACHE_STORE redis/database) — audit F-05.
// CMS: zamanlanmış içerik yayını.
Schedule::command('content:publish-scheduled')->everyMinute()->withoutOverlapping()->onOneServer();
// Booking: onaysız talepler süresi dolunca EXPIRED (saat serbest kalır).
Schedule::command('booking:expire-requests')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
// Üyelikler: bitişi geçen aktif üyelik expired (faz 39b).
Schedule::command('subscriptions:renew')->dailyAt('00:05')->withoutOverlapping()->onOneServer();  // auto_renew: yeni dönem + fatura (audit P1-5)
Schedule::command('subscriptions:expire')->dailyAt('00:10')->withoutOverlapping()->onOneServer();
Schedule::command('subscriptions:remind-expiring')->dailyAt('08:00')->withoutOverlapping()->onOneServer(); // bitişe N gün kala (P1-8)
// Faturalar: vade + tolerans geçen yayınlanmış fatura gecikmiş (faz 39c).
Schedule::command('invoices:mark-overdue')->dailyAt('00:20')->withoutOverlapping()->onOneServer();
// Tahsilat otomasyonu (audit P1-7): vade hatırlatması ve uzun gecikmede askıya alma (ayarlar › finans).
Schedule::command('invoices:remind-due')->dailyAt('08:10')->withoutOverlapping()->onOneServer();
Schedule::command('finance:suspend-overdue')->dailyAt('00:25')->withoutOverlapping()->onOneServer();
// Alan tahsisleri: bitişi geçen tahsis sona erer (audit P0-2).
Schedule::command('spaces:end-expired')->dailyAt('00:30')->withoutOverlapping()->onOneServer();

// SEO veri senkronları (faz 60d): sağlayıcı kapalıyken komut yazmaz, durum kaydeder; ölçüm haftalık (PSI kotası).
Schedule::command('ofisvio:search-console-sync')->dailyAt('04:00')->withoutOverlapping()->onOneServer();
Schedule::command('ofisvio:analytics-sync')->dailyAt('04:20')->withoutOverlapping()->onOneServer();
Schedule::command('ofisvio:web-vitals')->weeklyOn(1, '04:40')->withoutOverlapping()->onOneServer();
Schedule::command('ofisvio:content-refresh-scan')->weeklyOn(1, '05:00')->withoutOverlapping()->onOneServer(); // yenileme adayları (faz 60e)
// Sistem alarmı (audit F-18): doctor hatası / failed_jobs → Bildirim Merkezi system.alert (6 saatte bir tekrar).
Schedule::command('ofisvio:health-alert')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
// KVKK saklama (audit F-09): süresi dolan vitrin kayıtları anonimleşir, eski KYC dosyaları imha (ayarlar › gizlilik).
Schedule::command('ofisvio:retention')->dailyAt('03:00')->withoutOverlapping()->onOneServer();
// Giriş geçmişi 180 gün (LoginEvent::prunable).
Schedule::command('model:prune', ['--model' => [LoginEvent::class, PerformanceSample::class, SlowQuery::class, CacheEvent::class, WebhookDelivery::class]])->daily()->withoutOverlapping()->onOneServer(); // performans/önbellek izleri ve webhook teslimat logu 30 gün (faz 60f, 61c)
