<?php

namespace Database\Seeders;

use App\Models\SiteBlock;
use App\Models\Website;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Vitrin blokları ve varsayılan sitenin iletişim/kimlik bilgisi — kaynak
 * database/seeders/data/site_blocks.json (audit: ticari içerik config'te değil,
 * veritabanında yaşar; panelden düzenlenir).
 *
 * firstOrCreate / yalnız-boşsa-doldur: yeniden seed panelde yapılmış
 * düzenlemeyi EZMEZ. Blok silinmişse (varsayılana dönüş) yeniden gelir —
 * bu, "kod varsayılanı" kavramının veritabanı karşılığıdır.
 */
class SiteBlockSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/site_blocks.json');
        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data) || ! isset($data['blocks'], $data['website'])) {
            throw new RuntimeException('site_blocks.json okunamadı ya da biçimi bozuk.');
        }

        $website = Website::query()->default()->first();

        if ($website === null) {
            throw new RuntimeException('Varsayılan site yok — önce WebsiteSeeder.');
        }

        // İletişim/kimlik: yalnız boş alanlar doldurulur.
        $dirty = false;

        foreach ($data['website'] as $field => $value) {
            if ($website->{$field} === null || $website->{$field} === '') {
                $website->{$field} = $value;
                $dirty = true;
            }
        }

        if ($dirty) {
            $website->save();
        }

        $created = 0;

        foreach ($data['blocks'] as $key => $value) {
            $block = SiteBlock::query()->firstOrCreate(
                ['website_id' => $website->id, 'key' => $key],
                ['data' => $value],
            );

            if ($block->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->command?->info("Vitrin blokları: {$created} yeni, ".(count($data['blocks']) - $created).' mevcut korundu.');
    }
}
