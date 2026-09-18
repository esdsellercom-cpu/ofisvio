<?php

namespace App\Console\Commands;

use App\Content\BlogStarter;
use App\Content\CoverArtist;
use App\Models\Content;
use App\Models\Location;
use App\Models\Service;
use App\Models\User;
use App\Services\ContentService;
use App\Services\MediaService;
use App\Services\SiteBlockService;
use App\Support\TurkishSuffix;
use Illuminate\Console\Command;
use Throwable;

/**
 * Başlangıç blog setini kurar (faz 58): hizmetlerle ilişkili 14 yazı — SEO/GEO alanları, marka kapak görseli (medya
 * kütüphanesine gerçek Media kaydı), iç bağlantılar (yalnız var olan sayfalara) — ve isteğe bağlı yayın. Yinelenebilir:
 * var olan slug atlanır. Yerel (şehir) yazıları yalnız yayında tek şube varsa ve o şehirle kurulur.
 */
class BlogStarterCommand extends Command
{
    protected $signature = 'ofisvio:blog-starter {--draft : Yayınlama, taslak bırak} {--user= : Yazar e-postası (varsayılan: ilk personel)}';

    protected $description = 'Hizmetlerle ilişkili başlangıç blog yazılarını (SEO/GEO + kapak + iç bağlantı) kurar; var olanları atlar.';

    public function handle(ContentService $contents, MediaService $media, SiteBlockService $blocks): int
    {
        $website = $contents->defaultWebsiteOrNull();

        if ($website === null) {
            $this->error('Varsayılan site yok; önce db:seed.');

            return self::FAILURE;
        }

        $author = $this->option('user') ? User::query()->where('email', (string) $this->option('user'))->first() : User::query()->orderBy('id')->first();

        if ($author === null) {
            $this->error('Yazar bulunamadı; --user=e-posta verin ya da bir hesap açın (ofisvio:make-admin).');

            return self::FAILURE;
        }

        $single = $blocks->singleLocation();
        $city = $single?->city ?: '';
        $links = $this->links($single);
        $created = 0;
        $skipped = 0;

        foreach (BlogStarter::posts() as $i => $post) {
            $local = str_contains(json_encode($post, JSON_UNESCAPED_UNICODE) ?: '', '{city');

            if ($local && $city === '') {
                $this->line("  atlandı (yerel yazı, tek şube yok): {$post['slug']}");
                $skipped++;

                continue;
            }

            if (Content::withTrashed()->where('website_id', $website->id)->where('kind', 'post')->where('slug', $post['slug'])->exists()) {
                $skipped++;

                continue;
            }

            $fill = fn (string $text): string => strtr($text, $links + ['{city}' => $city, '{city_da}' => TurkishSuffix::locative($city)]);

            try {
                $cover = $media->uploadDataUrl($author, $website, 'data:image/png;base64,'.base64_encode(CoverArtist::png($post['theme'], $i)), [
                    'alt' => $fill($post['title']).' — kapak görseli',
                    'title' => $fill($post['title']),
                    'caption' => 'Marka illüstrasyonu; fotoğrafla değiştirilebilir.',
                    'seo_name' => $post['slug'].'-kapak',
                ]);
            } catch (Throwable $e) {
                $this->warn("  kapak yüklenemedi ({$post['slug']}): ".$e->getMessage());
                $cover = null;
            }

            $content = $contents->create($author, $website, [
                'kind' => 'post',
                'title' => $fill($post['title']),
                'slug' => $post['slug'],
                'excerpt' => $fill($post['excerpt']),
                'body' => $fill($post['body']),
                'category' => $post['category'],
                'tags' => $fill($post['tags']),
                'cover_media_id' => $cover?->id,
                'meta_title' => mb_substr($fill($post['meta_title']), 0, 70),
                'meta_description' => mb_substr($fill($post['meta_description']), 0, 160),
                'focus_keyword' => $fill($post['focus_keyword']),
                'related_keywords' => $fill($post['related_keywords']),
                'og_media_id' => $cover?->id,
                'schema_types' => ['Article', 'FAQPage', 'BreadcrumbList'],
                'geo' => ['summary' => $fill($post['summary']), 'faq' => $fill($post['faq']), 'topic' => $fill($post['focus_keyword'])],
                'is_featured' => $post['featured'],
            ]);

            if (! $this->option('draft')) {
                $contents->publishNow($author, $content);
            }

            $created++;
            $this->line('  '.($this->option('draft') ? 'taslak' : 'yayın').": /blog/{$post['slug']}");
        }

        $this->info("Blog seti: {$created} oluşturuldu, {$skipped} atlandı.");

        return self::SUCCESS;
    }

    /**
     * Yer tutucu → gerçek yol: hizmetler slug ile (yoksa /cozumler), tek şube (yoksa /lokasyonlar), teklif çapası, yazılar.
     *
     * @return array<string, string>
     */
    private function links(?Location $single): array
    {
        $service = fn (string $slug): string => Service::query()->active()->where('slug', $slug)->exists() ? '/cozum/'.$slug : '/cozumler';
        $links = [
            '{sanal}' => $service('sanal-ofis'),
            '{hazir}' => $service('hazir-ofis'),
            '{toplanti}' => $service('toplanti-odasi'),
            '{cowork}' => $service('coworking'),
            '{gunluk}' => $service('gunluk-kullanim'),
            '{cozumler}' => '/cozumler',
            '{lokasyon}' => $single !== null ? $single->path() : '/lokasyonlar',
            '{teklif}' => '/#teklif',
        ];

        foreach (BlogStarter::posts() as $post) {
            $links['{post:'.$post['slug'].'}'] = '/blog/'.$post['slug'];
        }

        return $links;
    }
}
