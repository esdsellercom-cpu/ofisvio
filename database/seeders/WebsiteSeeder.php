<?php

namespace Database\Seeders;

use App\Enums\ContentKind;
use App\Models\Content;
use App\Models\Website;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Varsayılan website (Ofisvio vitrini) + başlangıç içerik iskeleti.
 *
 * REFERANS veri, ticari veri değil (bkz. RolePermissionSeeder başlığı):
 * içerikler TASLAK olarak açılır — gövdesiz metin yayınlanmaz ("Boş içerik
 * yayınlanamaz" kuralı). Editör panelden yazar, akıştan geçirir, yayınlar.
 * Böylece vitrinde uydurma metin görünmez; yazı yoksa bölüm gizlenir.
 *
 * updateOrCreate: yeniden seed mevcut düzenlemeleri ezmez (slug anahtar).
 */
class WebsiteSeeder extends Seeder
{
    /** @var array<int, array{title: string, category: string, excerpt: string}> */
    private const POST_SKELETONS = [
        ['title' => 'Sanal ofisle şirket kurmanın gerçek maliyeti', 'category' => 'Sanal Ofis', 'excerpt' => 'Tescil, muhasebe ve adres kalemlerini tek tabloda karşılaştırdık.'],
        ['title' => 'Tescil adresi için hangi belgeler isteniyor?', 'category' => 'Mevzuat', 'excerpt' => 'Vergi levhası, sicil gazetesi, imza sirküleri: neyin neden istendiği.'],
        ['title' => 'Haftada üç gün ofis: ekip verimini bozmayan takvim', 'category' => 'Hibrit Çalışma', 'excerpt' => 'Dört ekiple yürüttüğümüz denemenin sonuçları ve uyguladığımız kurallar.'],
    ];

    /** Yasal sayfalar: onay zorunlu (requires_approval) — matris "P0B". */
    private const LEGAL_PAGES = ['Aydınlatma Metni', 'Gizlilik Politikası', 'Kullanım Koşulları'];

    public function run(): void
    {
        $website = Website::query()->updateOrCreate(
            ['slug' => 'ofisvio'],
            ['name' => 'Ofisvio', 'organization_id' => null, 'is_default' => true],
        );

        foreach (self::POST_SKELETONS as $post) {
            Content::query()->firstOrCreate(
                ['website_id' => $website->id, 'kind' => ContentKind::POST->value, 'slug' => Str::slug($post['title'])],
                ['title' => $post['title'], 'category' => $post['category'], 'excerpt' => $post['excerpt']],
            );
        }

        foreach (self::LEGAL_PAGES as $title) {
            Content::query()->firstOrCreate(
                ['website_id' => $website->id, 'kind' => ContentKind::PAGE->value, 'slug' => Str::slug($title)],
                ['title' => $title, 'requires_approval' => true],
            );
        }

        $this->command->info('Website seed tamamlandı: '.$website->name.' (varsayılan), '.count(self::POST_SKELETONS).' yazı + '.count(self::LEGAL_PAGES).' yasal sayfa taslağı.');
    }
}
