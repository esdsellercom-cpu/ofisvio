<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ana sayfaya Franchise / İş ortaklığı bölümü (faz 53). Yeni kurulumda SectionLibrary::defaultLayout zaten içerir;
 * mevcut sitelerde taslağa (teklif formundan önce) eklenir ve yayınlanmış son revizyona aynı konumda yeni revizyon
 * olarak yazılır — bölüm ayarsızdır (varsayılan metin), ticari veri taşımaz. Zaten franchise bölümü olan site atlanır.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('websites')->pluck('id') as $websiteId) {
            $sections = DB::table('site_sections')->where('website_id', $websiteId)->orderBy('sort_order')->get();

            if ($sections->isNotEmpty() && ! $sections->contains('type', 'franchise')) {
                $lead = $sections->firstWhere('type', 'lead_form');
                $position = $lead === null ? $sections->count() + 1 : (int) $lead->sort_order;

                DB::table('site_sections')->where('website_id', $websiteId)->where('sort_order', '>=', $position)->increment('sort_order');
                DB::table('site_sections')->insert([
                    'website_id' => $websiteId, 'type' => 'franchise', 'anchor' => 'franchise', 'sort_order' => $position,
                    'is_visible' => true, 'hide_on_mobile' => false, 'hide_on_desktop' => false, 'settings' => json_encode([]),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $latest = DB::table('site_revisions')->where('website_id', $websiteId)->orderByDesc('number')->first();

            if ($latest === null) {
                continue;
            }

            $snapshot = json_decode((string) $latest->snapshot, true);

            if (! is_array($snapshot) || collect($snapshot)->contains(fn ($r) => is_array($r) && ($r['type'] ?? null) === 'franchise')) {
                continue;
            }

            $row = ['type' => 'franchise', 'anchor' => 'franchise', 'is_visible' => true, 'hide_on_mobile' => false, 'hide_on_desktop' => false, 'locked' => false, 'label' => null, 'preset_id' => null, 'settings' => [], 'publish_from' => null, 'publish_until' => null];
            $index = collect($snapshot)->search(fn ($r) => is_array($r) && ($r['type'] ?? null) === 'lead_form');
            array_splice($snapshot, $index === false ? count($snapshot) : (int) $index, 0, [$row]);

            DB::table('site_revisions')->insert([
                'website_id' => $websiteId, 'number' => (int) $latest->number + 1, 'snapshot' => json_encode(array_values($snapshot), JSON_UNESCAPED_UNICODE),
                'note' => 'Franchise bölümü eklendi (faz 53)', 'created_by' => null, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('site_sections')->where('type', 'franchise')->delete();
        DB::table('site_revisions')->where('note', 'Franchise bölümü eklendi (faz 53)')->delete();
    }
};
