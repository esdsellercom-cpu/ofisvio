<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Yalnızca REFERANS verisi: roller + izin matrisi, lokasyonlar, varsayılan
 * site ve taslak sayfa iskeleti. Kullanıcı/şifre seed edilmez (§3: sahte veri
 * yasağı) — ilk personel `php artisan ofisvio:make-admin`. Tüm seeder'lar
 * idempotent; `php artisan db:seed --force` her deploy'da güvenle koşar.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            ServiceSeeder::class,
            // LocationSeeder BURADA ÇAĞRILMAZ (faz 56b): şubeler işletme verisidir ve panelden açılır (Lokasyonlar › Yeni).
            // Örnek 14 şube yalnız test fixture'ıdır; üretime seed edilirse vitrin çoklu lokasyon moduna düşer.
            WebsiteSeeder::class,
            SiteBlockSeeder::class,
            NotificationRuleSeeder::class,
        ]);
    }
}
