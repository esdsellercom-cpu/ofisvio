<?php

use App\Site\SectionLibrary;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nasıl çalışır bölümü editörde tam düzenlenebilir oldu (faz 57): adımlar/bilgi kutusu ayara taşındı. Mevcut journey
 * bölümlerinde (taslak + son yayın) eksik anahtarlar varsayılanla doldurulur; admin metinleri (title/lede) korunur.
 */
return new class extends Migration
{
    public function up(): void
    {
        $defaults = SectionLibrary::journeyDefaults();

        foreach (DB::table('site_sections')->where('type', 'journey')->get(['id', 'settings']) as $row) {
            $settings = json_decode((string) $row->settings, true);
            $settings = is_array($settings) ? $settings : [];

            if (! isset($settings['steps'])) {
                DB::table('site_sections')->where('id', $row->id)->update(['settings' => json_encode(array_merge($defaults, $settings), JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
            }
        }

        foreach (DB::table('websites')->pluck('id') as $websiteId) {
            $latest = DB::table('site_revisions')->where('website_id', $websiteId)->orderByDesc('number')->first();
            $snapshot = $latest === null ? null : json_decode((string) $latest->snapshot, true);

            if (! is_array($snapshot)) {
                continue;
            }

            $changed = false;

            foreach ($snapshot as $i => $section) {
                if (is_array($section) && ($section['type'] ?? null) === 'journey' && ! isset($section['settings']['steps'])) {
                    $snapshot[$i]['settings'] = array_merge($defaults, is_array($section['settings'] ?? null) ? $section['settings'] : []);
                    $changed = true;
                }
            }

            if ($changed) {
                DB::table('site_revisions')->where('id', $latest->id)->update(['snapshot' => json_encode(array_values($snapshot), JSON_UNESCAPED_UNICODE)]);
            }
        }
    }

    public function down(): void
    {
        // Veri onarımı; geri alınmaz.
    }
};
