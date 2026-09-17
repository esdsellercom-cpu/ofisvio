<?php

namespace App\Services;

use App\Models\Location;
use App\Models\LocationMedia;
use App\Models\Media;
use App\Models\User;
use App\Models\Website;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Lokasyon görselleri (faz 3): yükle (MediaService karantina zinciri) → kategoriye bağla,
 * sırala, birincil/kapak seç, alt/başlık/altyazı, dosyayı değiştir, kaldır (kullanılmayan
 * medya dosyasıyla silinir). Her işlem denetim izli; her değişiklikte site önbellekleri
 * geçersizlenir (lokasyon kartları ana sayfada). Görseller operatörün varsayılan sitesine
 * (Website::default) bağlıdır — lokasyon operatör varlığıdır.
 */
class LocationMediaService
{
    public function __construct(
        private readonly MediaService $media,
        private readonly AuditService $audit,
        private readonly ContentCache $cache,
    ) {}

    /** @return Collection<int, LocationMedia> */
    public function links(Location $location): Collection
    {
        return LocationMedia::query()->with('media')->where('location_id', $location->id)->orderBy('category')->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * Envanter formu (faz 46): birden çok lokasyonun galerisi tek sorguda (kapak seçimi, JS lokasyona göre süzer).
     *
     * @param  array<int, int>|null  $locationIds  null = hepsi
     * @return Collection<int, LocationMedia>
     */
    public function linksFor(?array $locationIds): Collection
    {
        return LocationMedia::query()->with('media')->when($locationIds !== null, fn ($q) => $q->whereIn('location_id', $locationIds))->orderBy('location_id')->orderBy('category')->orderBy('sort_order')->get();
    }

    /**
     * Vitrin: kategori → görseller (yalnız onaylı medya). Kapak ayrı (locations.cover_media_id).
     *
     * @return array<string, Collection<int, LocationMedia>>
     */
    public function gallery(Location $location): array
    {
        $out = [];

        foreach ($this->links($location)->filter(fn (LocationMedia $l) => $l->media !== null && $l->media->status === Media::STATUS_APPROVED)->groupBy('category') as $category => $items) {
            if ($category !== 'cover') {
                $out[(string) $category] = $items->values();
            }
        }

        return $out;
    }

    /**
     * @param  array{category: string, alt?: string|null, title?: string|null, caption?: string|null, primary?: bool}  $data
     */
    public function upload(User $actor, Location $location, UploadedFile $file, array $data): LocationMedia
    {
        $category = $this->category($data['category']);
        $media = $this->media->upload($actor, $this->website(), $file, ['alt' => $data['alt'] ?? null, 'title' => $data['title'] ?? null, 'caption' => $data['caption'] ?? null]);

        return $this->attach($actor, $location, $media, $category, (bool) ($data['primary'] ?? false));
    }

    public function attach(User $actor, Location $location, Media $media, string $category, bool $primary = false): LocationMedia
    {
        $category = $this->category($category);

        $link = DB::transaction(function () use ($actor, $location, $media, $category, $primary) {
            $link = LocationMedia::query()->firstOrNew(['location_id' => $location->id, 'media_id' => $media->id, 'category' => $category]);

            if (! $link->exists) {
                $link->sort_order = (int) LocationMedia::query()->where('location_id', $location->id)->where('category', $category)->max('sort_order') + 1;
            }

            $link->updated_by = $actor->id;
            $link->save();

            if ($primary || $category === 'cover' || ! LocationMedia::query()->where('location_id', $location->id)->where('category', $category)->where('is_primary', true)->exists()) {
                $this->markPrimary($location, $link);
            }

            return $link;
        });

        $this->audit->record($actor, 'location_media.attached', 'location', $location->id, [], ['media_id' => $media->id, 'category' => $category]);
        $this->bump();

        return $link;
    }

    /** Kapak: locations.cover_media_id + cover kategorisinde birincil bağ. */
    public function setCover(User $actor, Location $location, LocationMedia $link): void
    {
        $this->assertOwn($location, $link);
        $before = ['cover_media_id' => $location->cover_media_id];

        DB::transaction(function () use ($actor, $location, $link) {
            $location->forceFill(['cover_media_id' => $link->media_id])->save();
            $cover = LocationMedia::query()->firstOrNew(['location_id' => $location->id, 'media_id' => $link->media_id, 'category' => 'cover']);
            $cover->updated_by = $actor->id;
            $cover->sort_order = 1;
            $cover->save();
            $this->markPrimary($location, $cover);
        });

        $this->audit->record($actor, 'location_media.cover_set', 'location', $location->id, $before, ['cover_media_id' => $link->media_id]);
        $this->bump();
    }

    public function setPrimary(User $actor, Location $location, LocationMedia $link): void
    {
        $this->assertOwn($location, $link);
        $this->markPrimary($location, $link);
        $this->audit->record($actor, 'location_media.primary_set', 'location', $location->id, [], ['media_id' => $link->media_id, 'category' => $link->category]);
        $this->bump();
    }

    /** @param  array<int, int>  $ids  kategorideki bağ id'leri, yeni sırayla */
    public function reorder(User $actor, Location $location, string $category, array $ids): void
    {
        $category = $this->category($category);
        $links = LocationMedia::query()->where('location_id', $location->id)->where('category', $category)->get()->keyBy('id');
        $position = 1;

        foreach ($ids as $id) {
            if ($links->has((int) $id)) {
                $links[(int) $id]->forceFill(['sort_order' => $position++, 'updated_by' => $actor->id])->save();
            }
        }

        foreach ($links as $link) {
            if (! in_array($link->id, array_map('intval', $ids), true)) {
                $link->forceFill(['sort_order' => $position++])->save();
            }
        }

        $this->audit->record($actor, 'location_media.reordered', 'location', $location->id, [], ['category' => $category, 'order' => array_map('intval', $ids)]);
        $this->bump();
    }

    public function move(User $actor, Location $location, LocationMedia $link, string $direction): void
    {
        $this->assertOwn($location, $link);
        $siblings = LocationMedia::query()->where('location_id', $location->id)->where('category', $link->category)->orderBy('sort_order')->orderBy('id')->get()->values();
        $index = $siblings->search(fn (LocationMedia $l) => $l->id === $link->id);
        $target = $direction === 'up' ? $index - 1 : $index + 1;

        if ($index === false || $target < 0 || $target >= $siblings->count()) {
            return;
        }

        $ids = $siblings->pluck('id')->all();
        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
        $this->reorder($actor, $location, $link->category, $ids);
    }

    /**
     * @param  array{alt?: string|null, title?: string|null, caption?: string|null}  $meta
     */
    public function updateMeta(User $actor, Location $location, LocationMedia $link, array $meta): void
    {
        $this->assertOwn($location, $link);
        $this->media->applyMeta($link->media, $meta, $actor);
        $this->bump();
    }

    /** Dosyayı değiştir: yeni medya karantina zincirinden geçer; bağ ve meta korunur; eski dosya kullanılmıyorsa silinir. */
    public function replace(User $actor, Location $location, LocationMedia $link, UploadedFile $file): LocationMedia
    {
        $this->assertOwn($location, $link);
        $old = $link->media;
        $new = $this->media->upload($actor, $this->website(), $file, ['alt' => $old->alt, 'title' => $old->title, 'caption' => $old->caption]);

        if ($new->id === $old->id) {
            throw new DomainException('Yüklenen dosya mevcut görselle aynı.');
        }

        DB::transaction(function () use ($actor, $location, $old, $new) {
            // Aynı lokasyonda aynı medya başka kategoride de bağlıysa hepsi yeni dosyaya geçer.
            LocationMedia::query()->where('location_id', $location->id)->where('media_id', $old->id)->update(['media_id' => $new->id, 'updated_by' => $actor->id]);

            if ((int) $location->cover_media_id === (int) $old->id) {
                $location->forceFill(['cover_media_id' => $new->id])->save();
            }
        });

        $this->audit->record($actor, 'location_media.replaced', 'location', $location->id, ['media_id' => $old->id], ['media_id' => $new->id]);

        if (! $old->fresh()?->isInUse()) {
            $this->media->delete($old, $actor);
        }

        $this->bump();

        return $link->fresh() ?? $link;
    }

    /** Bağı kaldır; medya başka yerde kullanılmıyorsa dosyasıyla sil. */
    public function detach(User $actor, Location $location, LocationMedia $link): void
    {
        $this->assertOwn($location, $link);
        $media = $link->media;
        $wasPrimary = $link->is_primary;
        $category = $link->category;

        DB::transaction(function () use ($location, $link, $media) {
            $link->delete();

            if ($link->category === 'cover' && (int) $location->cover_media_id === (int) $media->id) {
                $location->forceFill(['cover_media_id' => null])->save();
            }
        });

        if ($wasPrimary) {
            $next = LocationMedia::query()->where('location_id', $location->id)->where('category', $category)->orderBy('sort_order')->first();

            if ($next !== null) {
                $this->markPrimary($location, $next);
            }
        }

        $this->audit->record($actor, 'location_media.detached', 'location', $location->id, ['media_id' => $media->id, 'category' => $category], []);

        if (! $media->fresh()?->isInUse()) {
            $this->media->delete($media, $actor);
        }

        $this->bump();
    }

    public function find(Location $location, int $id): LocationMedia
    {
        $link = LocationMedia::query()->with('media')->where('location_id', $location->id)->find($id);

        if ($link === null) {
            throw new DomainException('Görsel bağı bulunamadı.');
        }

        return $link;
    }

    private function markPrimary(Location $location, LocationMedia $link): void
    {
        LocationMedia::query()->where('location_id', $location->id)->where('category', $link->category)->where('id', '!=', $link->id)->update(['is_primary' => false]);
        $link->forceFill(['is_primary' => true])->save();
    }

    private function assertOwn(Location $location, LocationMedia $link): void
    {
        if ((int) $link->location_id !== (int) $location->id) {
            throw new DomainException('Görsel bağı bu lokasyona ait değil.');
        }
    }

    private function category(string $category): string
    {
        if (! isset(LocationMedia::CATEGORIES[$category])) {
            throw new DomainException('Geçersiz görsel kategorisi.');
        }

        return $category;
    }

    private function website(): Website
    {
        return Website::query()->default()->firstOrFail();
    }

    /** Lokasyon görselleri ana sayfa kartlarında ve lokasyon sayfalarında: tüm site önbellekleri düşer. */
    private function bump(): void
    {
        foreach (Website::query()->get() as $website) {
            $this->cache->invalidate($website);
        }
    }
}
