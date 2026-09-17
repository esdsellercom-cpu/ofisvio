<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Hizmetler — referans veri (faz 4): operatörün çözüm kataloğu. Kaynak
 * database/seeders/data/services.json; kodda ad/fiyat yok. Slug ile eşleşir
 * (updateOrCreate): panelden düzenlenen alanlar re-seed'de KORUNUR — yalnız
 * eksik hizmet eklenir.
 */
class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $rows = (array) (json_decode((string) file_get_contents(database_path('seeders/data/services.json')), true)['services'] ?? []);
        $added = 0;

        foreach ($rows as $row) {
            $slug = Str::slug((string) $row['name']);

            if (Service::query()->where('slug', $slug)->exists()) {
                continue;
            }

            Service::query()->create([
                'name' => $row['name'], 'slug' => $slug, 'summary' => $row['summary'] ?? null, 'price_text' => $row['price_text'] ?? null,
                'booking_kind' => $row['booking_kind'] ?? null, 'is_flagship' => (bool) ($row['is_flagship'] ?? false), 'is_active' => true, 'sort_order' => (int) ($row['sort_order'] ?? 0),
            ]);
            $added++;
        }

        $this->command->info("Hizmet seed tamamlandı: {$added} yeni, ".Service::count().' toplam.');
    }
}
