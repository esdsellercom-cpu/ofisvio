<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Audit F-05: her zamanlanmış görev tek sunucuda (onOneServer) ve çakışmasız (withoutOverlapping) koşar —
 * çok sunuculu kurulumda gecikme bildirimi, webhook, durum geçişi iki kez üretilmez.
 */
class SchedulerIsolationTest extends TestCase
{
    #[Test]
    public function her_zamanlanmis_gorev_tek_sunucu_ve_cakismasiz(): void
    {
        $events = app(Schedule::class)->events();
        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            $name = $event->command ?? $event->description ?? 'closure';
            $this->assertTrue($event->onOneServer, "onOneServer eksik: {$name}");
            $this->assertTrue($event->withoutOverlapping, "withoutOverlapping eksik: {$name}");
        }
    }
}
