<?php

namespace Database\Seeders;

use App\Services\NotificationService;
use Illuminate\Database\Seeder;

/**
 * Referans veri: olay × kanal × grup varsayılan kural seti (NotificationEvents::defaults).
 * Yalnız hiç kural yoksa yazar; panelde değiştirilen kurallara dokunmaz. Alıcı (telefon/e-posta)
 * seed edilmez — env + ofisvio:bootstrap-notifications ya da panel.
 */
class NotificationRuleSeeder extends Seeder
{
    public function run(): void
    {
        app(NotificationService::class)->seedDefaultRules();
    }
}
