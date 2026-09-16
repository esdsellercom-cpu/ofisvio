<?php

use Illuminate\Support\Facades\Schedule;

// CMS: zamanlanmış içerik yayını. Üretimde cron: * * * * * php artisan schedule:run
Schedule::command('content:publish-scheduled')->everyMinute()->withoutOverlapping();
