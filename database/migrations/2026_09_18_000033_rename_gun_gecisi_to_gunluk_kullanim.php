<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Gün Geçişi" → "Günlük Kullanım" (tüm alanlar): hizmet kaydı (ad + slug), vitrin blokları (üyelik planı adı,
 * footer bağlantısı), üye profili üyelik tipi anahtarı ve eski /cozum/gun-gecisi yolu için 301 yönlendirme
 * (websites.seo_settings url.redirects). Panelden değiştirilmiş farklı bir ad varsa dokunulmaz (yalnız eski ad eşleşince).
 */
return new class extends Migration
{
    private const OLD_NAME = 'Gün Geçişi';

    private const NEW_NAME = 'Günlük Kullanım';

    public function up(): void
    {
        $renamed = false;

        foreach (DB::table('services')->where('slug', 'gun-gecisi')->get(['id', 'name']) as $service) {
            $data = ['slug' => 'gunluk-kullanim', 'updated_at' => now()];

            if ($service->name === self::OLD_NAME) {
                $data['name'] = self::NEW_NAME;
            }

            DB::table('services')->where('id', $service->id)->update($data);
            $renamed = true;
        }

        DB::table('member_profiles')->where('membership_type', 'gun_gecisi')->update(['membership_type' => 'gunluk_kullanim']);

        foreach (DB::table('site_blocks')->get(['id', 'data']) as $block) {
            $json = (string) $block->data;
            $next = str_replace([json_encode(self::OLD_NAME, JSON_UNESCAPED_UNICODE), json_encode(self::OLD_NAME), '/cozum/gun-gecisi'], [json_encode(self::NEW_NAME, JSON_UNESCAPED_UNICODE), json_encode(self::NEW_NAME, JSON_UNESCAPED_UNICODE), '/cozum/gunluk-kullanim'], $json);

            if ($next !== $json) {
                DB::table('site_blocks')->where('id', $block->id)->update(['data' => $next, 'updated_at' => now()]);
            }
        }

        if (! $renamed) {
            return;
        }

        foreach (DB::table('websites')->get(['id', 'seo_settings']) as $website) {
            $settings = json_decode((string) $website->seo_settings, true);
            $settings = is_array($settings) ? $settings : [];
            $rules = array_values(array_filter((array) ($settings['url.redirects'] ?? []), 'is_array'));

            if (collect($rules)->contains(fn (array $r) => ($r['from'] ?? '') === '/cozum/gun-gecisi')) {
                continue;
            }

            $rules[] = ['from' => '/cozum/gun-gecisi', 'to' => '/cozum/gunluk-kullanim', 'code' => '301'];
            $settings['url.redirects'] = $rules;
            DB::table('websites')->where('id', $website->id)->update(['seo_settings' => json_encode($settings, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void
    {
        DB::table('services')->where('slug', 'gunluk-kullanim')->update(['slug' => 'gun-gecisi']);
        DB::table('services')->where('name', self::NEW_NAME)->update(['name' => self::OLD_NAME]);
        DB::table('member_profiles')->where('membership_type', 'gunluk_kullanim')->update(['membership_type' => 'gun_gecisi']);
    }
};
