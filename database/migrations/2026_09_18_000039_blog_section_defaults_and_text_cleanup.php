<?php

use App\Models\SiteBlock;
use App\Models\Website;
use App\Services\SiteBlockService;
use App\Site\SectionLibrary;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Faz 58 veri onarımı:
 *  1) Blog bölümü yeni alanlar aldı (açıklama, kart görünümü, CTA "Tüm Yazıları Gör"): yeni alanları hiç görmemiş
 *     (layout anahtarı olmayan) bölümlerde varsayılan yazılır; admin metinleri korunur, normalize edilmiş boş CTA düşer.
 *  2) Metin ezmeleri: etkin varsayılana (tek lokasyon şehir bağlamı dahil) eşit olan kayıtlar ezme değil, tekrar —
 *     silinir ki şehir değişince eski metin kalmasın.
 */
return new class extends Migration
{
    public function up(): void
    {
        $defaults = SectionLibrary::defaults('blog');
        $kept = fn (array $settings): array => array_filter($settings, fn ($v, $k) => $k !== 'cta' && $v !== '' && $v !== [] && $v !== null, ARRAY_FILTER_USE_BOTH);

        foreach (DB::table('site_sections')->where('type', 'blog')->get(['id', 'settings']) as $row) {
            $settings = json_decode((string) $row->settings, true);
            $settings = is_array($settings) ? $settings : [];

            if (! isset($settings['layout'])) {
                DB::table('site_sections')->where('id', $row->id)->update(['settings' => json_encode(array_merge($defaults, $kept($settings)), JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
            }
        }

        foreach (DB::table('websites')->pluck('id') as $websiteId) {
            $latest = DB::table('site_revisions')->where('website_id', $websiteId)->orderByDesc('number')->first();
            $snapshot = $latest === null ? null : json_decode((string) $latest->snapshot, true);

            if (is_array($snapshot)) {
                $changed = false;

                foreach ($snapshot as $i => $section) {
                    if (is_array($section) && ($section['type'] ?? null) === 'blog' && ! isset($section['settings']['layout'])) {
                        $snapshot[$i]['settings'] = array_merge($defaults, $kept(is_array($section['settings'] ?? null) ? $section['settings'] : []));
                        $changed = true;
                    }
                }

                if ($changed) {
                    DB::table('site_revisions')->where('id', $latest->id)->update(['snapshot' => json_encode(array_values($snapshot), JSON_UNESCAPED_UNICODE)]);
                }
            }
        }

        // Metin ezmeleri: etkin varsayılana eşit olanlar düşer.
        $blocks = app(SiteBlockService::class);
        $effective = $blocks->defaultTexts();

        foreach (Website::query()->get() as $website) {
            $block = SiteBlock::query()->where('website_id', $website->id)->where('key', 'texts')->first();

            if ($block === null) {
                continue;
            }

            $data = array_filter((array) $block->data, fn ($v, $k) => ! isset($effective[$k]) || (string) $v !== $effective[$k], ARRAY_FILTER_USE_BOTH);

            if ($data === []) {
                $block->delete();
            } elseif (count($data) !== count($block->data)) {
                $block->forceFill(['data' => $data])->save();
            }
        }
    }

    public function down(): void
    {
        // Veri onarımı; geri alınmaz.
    }
};
