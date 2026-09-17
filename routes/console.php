<?php

use Illuminate\Support\Facades\Schedule;

// CMS: zamanlanmış içerik yayını. Üretimde cron: * * * * * php artisan schedule:run
Schedule::command('content:publish-scheduled')->everyMinute()->withoutOverlapping();
// Booking: onaysız talepler süresi dolunca EXPIRED (saat serbest kalır).
Schedule::command('booking:expire-requests')->everyFiveMinutes()->withoutOverlapping();
// Üyelikler: bitişi geçen aktif üyelik expired (faz 39b).
Schedule::command('subscriptions:expire')->dailyAt('00:10')->withoutOverlapping();
// Faturalar: vade + tolerans geçen yayınlanmış fatura gecikmiş (faz 39c).
Schedule::command('invoices:mark-overdue')->dailyAt('00:20')->withoutOverlapping();
// Alan tahsisleri: bitişi geçen tahsis sona erer (audit P0-2).
Schedule::command('spaces:end-expired')->dailyAt('00:30')->withoutOverlapping();
