<?php

use App\Models\CacheEvent;
use App\Models\LoginEvent;
use App\Models\PerformanceSample;
use App\Models\SlowQuery;
use Illuminate\Support\Facades\Schedule;

// CMS: zamanlanmış içerik yayını. Üretimde cron: * * * * * php artisan schedule:run
Schedule::command('content:publish-scheduled')->everyMinute()->withoutOverlapping();
// Booking: onaysız talepler süresi dolunca EXPIRED (saat serbest kalır).
Schedule::command('booking:expire-requests')->everyFiveMinutes()->withoutOverlapping();
// Üyelikler: bitişi geçen aktif üyelik expired (faz 39b).
Schedule::command('subscriptions:renew')->dailyAt('00:05')->withoutOverlapping();  // auto_renew: yeni dönem + fatura (audit P1-5)
Schedule::command('subscriptions:expire')->dailyAt('00:10')->withoutOverlapping();
Schedule::command('subscriptions:remind-expiring')->dailyAt('08:00')->withoutOverlapping(); // bitişe N gün kala (P1-8)
// Faturalar: vade + tolerans geçen yayınlanmış fatura gecikmiş (faz 39c).
Schedule::command('invoices:mark-overdue')->dailyAt('00:20')->withoutOverlapping();
// Tahsilat otomasyonu (audit P1-7): vade hatırlatması ve uzun gecikmede askıya alma (ayarlar › finans).
Schedule::command('invoices:remind-due')->dailyAt('08:10')->withoutOverlapping();
Schedule::command('finance:suspend-overdue')->dailyAt('00:25')->withoutOverlapping();
// Alan tahsisleri: bitişi geçen tahsis sona erer (audit P0-2).
Schedule::command('spaces:end-expired')->dailyAt('00:30')->withoutOverlapping();

// SEO veri senkronları (faz 60d): sağlayıcı kapalıyken komut yazmaz, durum kaydeder; ölçüm haftalık (PSI kotası).
Schedule::command('ofisvio:search-console-sync')->dailyAt('04:00')->withoutOverlapping();
Schedule::command('ofisvio:analytics-sync')->dailyAt('04:20')->withoutOverlapping();
Schedule::command('ofisvio:web-vitals')->weeklyOn(1, '04:40')->withoutOverlapping();
Schedule::command('ofisvio:content-refresh-scan')->weeklyOn(1, '05:00')->withoutOverlapping(); // yenileme adayları (faz 60e)
// Giriş geçmişi 180 gün (LoginEvent::prunable).
Schedule::command('model:prune', ['--model' => [LoginEvent::class, PerformanceSample::class, SlowQuery::class, CacheEvent::class]])->daily(); // performans/önbellek izleri 30 gün (faz 60f)
