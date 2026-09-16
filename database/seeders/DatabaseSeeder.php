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
            LocationSeeder::class,
            WebsiteSeeder::class,
            SiteBlockSeeder::class,
        ]);
    }
}
