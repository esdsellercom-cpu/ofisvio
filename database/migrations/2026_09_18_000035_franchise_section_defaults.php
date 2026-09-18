<?php

use App\Site\SectionLibrary;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ayarsız (boş) franchise bölümleri varsayılan ayarları alır (faz 53 düzeltmesi): editörde kaydedince CTA "none"
 * olarak normalize edilip vitrindeki varsayılan "Franchise Başvurusu" düğmesi kayboluyordu. Taslak + son yayın revizyonu.
 */
return new class extends Migration
{
    public function up(): void
    {
        $defaults = SectionLibrary::defaults('franchise');

        foreach (DB::table('site_sections')->where('type', 'franchise')->get(['id', 'settings']) as $row) {
            $settings = json_decode((string) $row->settings, true);

            if (! is_array($settings) || $settings === [] || ! isset($settings['title'])) {
                DB::table('site_sections')->where('id', $row->id)->update(['settings' => json_encode(array_merge($defaults, self::kept(is_array($settings) ? $settings : [])), JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
            }
        }

        foreach (DB::table('websites')->pluck('id') as $websiteId) {
            $latest = DB::table('site_revisions')->where('website_id', $websiteId)->orderByDesc('number')->first();

            if ($latest === null) {
                continue;
            }

            $snapshot = json_decode((string) $latest->snapshot, true);

            if (! is_array($snapshot)) {
                continue;
            }

            $changed = false;

            foreach ($snapshot as $i => $section) {
                if (is_array($section) && ($section['type'] ?? null) === 'franchise' && (! is_array($section['settings'] ?? null) || ! isset($section['settings']['title']))) {
                    $snapshot[$i]['settings'] = array_merge($defaults, self::kept(is_array($section['settings'] ?? null) ? $section['settings'] : []));
                    $changed = true;
                }
            }

            if ($changed) {
                DB::table('site_revisions')->where('id', $latest->id)->update(['snapshot' => json_encode(array_values($snapshot), JSON_UNESCAPED_UNICODE)]);
            }
        }
    }

    /** Hiç düzenlenmemiş bölümde yalnız dolu metin/stil alanları korunur; normalize edilmiş boş CTA (none) varsayılana bırakılır. @param array<string, mixed> $settings @return array<string, mixed> */
    private static function kept(array $settings): array
    {
        return array_filter($settings, fn ($v, $k) => $k !== 'cta' && $v !== '' && $v !== [] && $v !== null, ARRAY_FILTER_USE_BOTH);
    }

    public function down(): void
    {
        // Veri onarımı; geri alınmaz.
    }
};
