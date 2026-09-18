<?php

namespace App\Services;

use App\Models\Content;
use App\Models\Location;
use App\Models\Media;
use App\Models\Service;
use App\Models\SiteRevision;
use App\Models\SiteSection;
use App\Models\User;
use App\Models\Website;
use App\Site\SectionLibrary;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Canlı düzenleme (faz 59): admin vitrinde gezerken bir görseli yerinde değiştirir. Yazma yolları mevcut servislerin
 * kurallarını korur — hero site ayarı (website.manage), bölüm görselleri taslak + yayın anlık görüntüsü (content.edit),
 * içerik kapağı (content.edit), hizmet kapağı (service.manage), lokasyon kapağı yalnız galeriden (geo.edit; galeriye
 * eklenip kapak yapılır). Yetki rotada; burada kaynak sahipliği (site) doğrulanır ve her değişiklik audit'e yazılır.
 *
 * Görsel hedefi kimliği (görünümlerde data-le): kind:id:field[:index] — website:{id}:hero · section:{id}:media[:i] ·
 * content:{id}:cover · service:{id}:cover · location:{id}:cover. Yeni hedef türü = bu sınıfa metot + rota + görünüm işareti.
 */
class LiveEditService
{
    public const KINDS = ['website' => 'website.manage', 'section' => 'content.edit', 'content' => 'content.edit', 'service' => 'service.manage', 'location' => 'geo.edit'];

    public function __construct(
        private readonly MediaService $media,
        private readonly LocationMediaService $locationMedia,
        private readonly ContentCache $cache,
        private readonly AuditService $audit,
    ) {}

    /**
     * Seçilen ya da yüklenen görsel: id verildiyse sitenin kütüphanesinden; dosya verildiyse karantina zincirinden yüklenir.
     * Alt/başlık verildiyse medya kaydına işlenir (kütüphane geneli — aynı görsel her yerde bu alt metni taşır).
     *
     * @param  array{alt?: string|null, caption?: string|null}  $meta
     */
    public function resolveMedia(User $actor, Website $website, ?int $mediaId, ?UploadedFile $file, array $meta = []): Media
    {
        if ($file !== null) {
            return $this->media->upload($actor, $website, $file, array_filter($meta, fn ($v) => $v !== null && $v !== ''));
        }

        $media = $mediaId !== null ? Media::query()->where('website_id', $website->id)->find($mediaId) : null;

        if ($media === null) {
            throw new DomainException('Görsel bulunamadı ya da bu siteye ait değil.');
        }

        if (array_key_exists('alt', $meta) || array_key_exists('caption', $meta)) {
            $this->media->applyMeta($media, $meta, $actor);
        }

        return $media;
    }

    /** Site ana görseli (hero) — null = kaldır. */
    public function setWebsiteHero(User $actor, Website $website, ?Media $media): void
    {
        $before = ['hero_media_id' => $website->hero_media_id];
        $website->forceFill(['hero_media_id' => $media?->id])->save();
        $this->cache->invalidate($website);
        $this->audit->record($actor, 'live.image_replaced', 'website', $website->id, $before, ['hero_media_id' => $media?->id, 'target' => 'website:'.$website->id.':hero']);
    }

    /**
     * Bölüm görseli (media alanı ya da media_list[i]): taslaktaki bölüm + yayınlanmış son revizyondaki karşılığı birlikte
     * güncellenir — canlı sayfada görünen değişir, taslak da aynı değeri taşır (yayınla eşit rozeti bozulmaz). Diğer
     * taslak değişiklikleri YAYINLANMAZ.
     */
    public function setSectionMedia(User $actor, Website $website, int $sectionId, string $field, ?int $index, ?Media $media): void
    {
        $section = SiteSection::query()->where('website_id', $website->id)->find($sectionId) ?? throw new DomainException('Bölüm bulunamadı.');
        $def = SectionLibrary::type($section->type);
        $type = $def['fields'][$field]['type'] ?? null;

        if (! in_array($type, ['media', 'media_list'], true)) {
            throw new DomainException('Bu alan görsel alanı değil.');
        }

        $apply = function (array $settings) use ($type, $field, $index, $media): array {
            if ($type === 'media') {
                $settings[$field] = $media !== null ? $media->id : '';
            } else {
                $list = array_values(array_map('intval', (array) ($settings[$field] ?? [])));

                if ($index === null || $index < 0) {
                    if ($media !== null) {
                        $list[] = $media->id;
                    }
                } elseif ($media === null) {
                    array_splice($list, $index, 1);
                } else {
                    $list[$index] = $media->id;
                }

                $settings[$field] = $list;
            }

            return array_filter($settings, fn ($v) => $v !== '' && $v !== []);
        };

        $before = $section->settings ?? [];

        DB::transaction(function () use ($section, $website, $apply) {
            $section->forceFill(['settings' => $apply($section->settings ?? [])])->save();

            $revision = SiteRevision::query()->where('website_id', $website->id)->orderByDesc('number')->first();

            if ($revision === null) {
                return;
            }

            $snapshot = (array) $revision->snapshot;
            $matched = false;

            foreach ($snapshot as $i => $row) {
                if (! is_array($row)) {
                    continue;
                }

                // Eşleme: anlık görüntüde id varsa onunla; yoksa tür + çapa + etiket (tekil bölümler için yeterli).
                $same = isset($row['id']) ? (int) $row['id'] === $section->id : (($row['type'] ?? null) === $section->type && ($row['anchor'] ?? null) === $section->anchor && ($row['label'] ?? null) === $section->label);

                if ($same && ! $matched) {
                    $snapshot[$i]['settings'] = $apply((array) ($row['settings'] ?? []));
                    $snapshot[$i]['id'] = $section->id;
                    $matched = true;
                }
            }

            if ($matched) {
                $revision->forceFill(['snapshot' => array_values($snapshot)])->save();
            }
        });

        $this->cache->invalidate($website);
        $this->audit->record($actor, 'live.image_replaced', 'site_section', $section->id, ['settings' => $before], ['settings' => $section->fresh()?->settings, 'target' => 'section:'.$section->id.':'.$field.($index !== null ? ':'.$index : '')]);
    }

    /** İçerik kapağı (yazı/sayfa): yayın gövdesinden bağımsız (çalışma taslağı kapak taşımaz), anında. */
    public function setContentCover(User $actor, Website $website, Content $content, ?Media $media): void
    {
        if ((int) $content->website_id !== (int) $website->id) {
            throw new DomainException('İçerik bu siteye ait değil.');
        }

        $before = ['cover_media_id' => $content->cover_media_id];
        $content->forceFill(['cover_media_id' => $media?->id, 'cover_url' => $media?->url()])->save();
        $this->cache->invalidate($website);
        $this->audit->record($actor, 'live.image_replaced', 'content', $content->id, $before, ['cover_media_id' => $media?->id, 'target' => 'content:'.$content->id.':cover']);
    }

    /** Hizmet kapağı. */
    public function setServiceCover(User $actor, Website $website, Service $service, ?Media $media): void
    {
        $before = ['cover_media_id' => $service->cover_media_id];
        $service->forceFill(['cover_media_id' => $media?->id, 'updated_by' => $actor->id])->save();
        $this->cache->invalidate($website);
        $this->audit->record($actor, 'live.image_replaced', 'service', $service->id, $before, ['cover_media_id' => $media?->id, 'target' => 'service:'.$service->id.':cover']);
    }

    /** Lokasyon kapağı: kural gereği yalnız galeriden — görsel galeride değilse eklenir, sonra kapak yapılır. null = kaldır. */
    public function setLocationCover(User $actor, Website $website, Location $location, ?Media $media): void
    {
        if ($media === null) {
            $before = ['cover_media_id' => $location->cover_media_id];
            $location->forceFill(['cover_media_id' => null])->save();
            $this->cache->invalidate($website);
            $this->audit->record($actor, 'live.image_replaced', 'location', $location->id, $before, ['cover_media_id' => null, 'target' => 'location:'.$location->id.':cover']);

            return;
        }

        $link = $this->locationMedia->attach($actor, $location, $media, 'gallery');
        $this->locationMedia->setCover($actor, $location, $link);
        $this->cache->invalidate($website);
        $this->audit->record($actor, 'live.image_replaced', 'location', $location->id, [], ['cover_media_id' => $media->id, 'target' => 'location:'.$location->id.':cover']);
    }

    /**
     * Modal için kütüphane: id, küçük görsel, tam adres, alt, ad. Yalnız yetkili oturumda görünüme basılır.
     *
     * @return list<array{id: int, url: string, thumb: string, alt: string, name: string}>
     */
    public function library(Website $website): array
    {
        return $this->media->all($website)->map(fn (Media $m) => ['id' => $m->id, 'url' => $m->url(), 'thumb' => $m->urlFor(320), 'alt' => (string) $m->alt, 'name' => (string) ($m->original_name ?? '')])->values()->all();
    }
}
