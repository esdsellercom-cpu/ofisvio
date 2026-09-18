<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Room;
use App\Models\Service;
use App\Models\User;
use App\Models\Website;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Hizmet modülü (faz 4): hizmetler tek kaynaktır — vitrin çözüm kartları, lokasyon kart
 * etiketleri, süzgeç ve teklif formu seçenekleri, JSON-LD Service düğümleri, hizmet
 * sayfaları buradan beslenir. Lokasyon ekranı hizmet OLUŞTURMAZ, yalnız seçer.
 * Her değişiklikte site önbellekleri düşer (ana sayfa/kartlar); audit izli.
 */
class ServiceService
{
    public function __construct(private readonly AuditService $audit, private readonly ContentCache $cache, private readonly UrlHistoryService $urls) {}

    /**
     * Vitrin: aktif hizmetler (önbellekli; site sürümüyle).
     *
     * @return Collection<int, Service>
     */
    public function active(?Website $website = null): Collection
    {
        if ($website === null) {
            return Service::query()->active()->get();
        }

        $rows = $this->cache->remember($website, 'services', fn () => Service::query()->active()->get()->map(fn (Service $s) => $s->getAttributes())->all());
        $hydrated = Service::query()->hydrate(is_array($rows) ? array_values(array_filter($rows, 'is_array')) : []);

        return $hydrated;
    }

    /** Teklif formu / süzgeç seçenekleri: aktif hizmet adları. @return array<int, string> */
    public function names(?Website $website = null): array
    {
        return $this->active($website)->pluck('name')->values()->all();
    }

    /** @return Collection<int, Service> */
    public function all(): Collection
    {
        return Service::query()->withCount('locations')->orderBy('sort_order')->orderBy('name')->get();
    }

    public function findActive(string $slug): ?Service
    {
        return Service::query()->active()->where('slug', $slug)->with('cover')->first();
    }

    /**
     * Hizmeti sunan yayındaki lokasyonlar.
     *
     * @return Collection<int, Location>
     */
    public function locationsFor(Service $service): Collection
    {
        return $service->locations()->published()->with('cover')->get();
    }

    /**
     * booking_kind dolu hizmet için rezervasyona açık odalar.
     *
     * @return Collection<int, Room>
     */
    public function roomsFor(Service $service): Collection
    {
        if ($service->booking_kind === null) {
            return new Collection;
        }

        return Room::query()->where('kind', $service->booking_kind)->where('is_active', true)
            ->whereHas('location', fn (Builder $q) => $q->where('is_active', true)->where('is_published', true))
            ->with('location')->orderBy('hourly_rate')->get();
    }

    /**
     * @param  array{name: string, summary?: string|null, description?: string|null, price_text?: string|null, booking_kind?: string|null, is_flagship?: bool, is_active?: bool, sort_order?: int|null, cover_media_id?: int|null}  $data
     */
    public function create(User $actor, array $data): Service
    {
        $service = new Service($this->attributes($data));
        $service->slug = $this->uniqueSlug($data['name']);
        $service->updated_by = $actor->id;
        $service->save();
        $this->audit->record($actor, 'service.created', 'service', $service->id, [], $service->toArray());
        $this->bump();

        return $service;
    }

    /** @param  array<string, mixed>  $data */
    public function update(User $actor, Service $service, array $data): Service
    {
        $before = $service->toArray();
        $service->fill($this->attributes($data));
        $service->updated_by = $actor->id;
        $service->save(); // slug değişmez (dış bağlantılar, sitemap)
        $this->audit->record($actor, 'service.updated', 'service', $service->id, $before, $service->toArray());
        $this->bump();

        return $service;
    }

    /** Silme: lokasyona bağlı hizmet silinemez (önce kaldır) — sessiz kopukluk yok. */
    /** @param  string|null  $redirectTo  Silinen /cozum/{slug} adresi için hedef (faz 54); null = yalnız URL geçmişi. */
    public function delete(User $actor, Service $service, ?string $redirectTo = null): void
    {
        if ($service->locations()->exists()) {
            throw new DomainException('Bu hizmet lokasyonlara bağlı; önce lokasyonlardan kaldırın ya da pasife alın.');
        }

        $before = $service->toArray();
        $snapshot = RedirectService::snapshotOf($service);
        $service->delete();
        $this->audit->record($actor, 'service.deleted', 'service', $service->id, $before, []);
        $this->bump();

        $website = Website::query()->default()->first();

        if ($website !== null) {
            $this->urls->recordDeletion($website, 'service', $service->id, $service->path(), $snapshot, $redirectTo, $actor);
        }
    }

    /**
     * Lokasyonun hizmetlerini eşitler (geo.edit). Yalnız var olan hizmet id'leri; yeni hizmet buradan açılmaz.
     *
     * @param  array<int, int>  $serviceIds
     */
    public function syncLocation(User $actor, Location $location, array $serviceIds): void
    {
        $valid = Service::query()->whereIn('id', array_map('intval', $serviceIds))->pluck('id')->all();
        $before = $location->services()->pluck('services.id')->all();
        $sync = [];

        foreach (array_values(array_unique($valid)) as $i => $id) {
            $sync[$id] = ['sort_order' => $i + 1];
        }

        $location->services()->sync($sync);
        $location->unsetRelation('services');

        if (array_values($before) !== array_keys($sync)) {
            $this->audit->record($actor, 'location.services_changed', 'location', $location->id, ['services' => $before], ['services' => array_keys($sync)]);
            $this->bump();
        }
    }

    /** @param  array<string, mixed>  $data  @return array<string, mixed> */
    private function attributes(array $data): array
    {
        $kind = trim((string) ($data['booking_kind'] ?? ''));

        if ($kind !== '' && ! isset(Service::BOOKING_KINDS[$kind])) {
            throw new DomainException('Geçersiz rezervasyon türü.');
        }

        return [
            'name' => trim((string) $data['name']),
            'summary' => $this->blank($data['summary'] ?? null),
            'description' => $this->blank($data['description'] ?? null),
            'price_text' => $this->blank($data['price_text'] ?? null),
            'booking_kind' => $kind === '' ? null : $kind,
            'is_flagship' => (bool) ($data['is_flagship'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'cover_media_id' => ! empty($data['cover_media_id']) ? (int) $data['cover_media_id'] : null,
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'hizmet';
        $slug = $base;

        for ($i = 2; Service::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    private function blank(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function bump(): void
    {
        foreach (Website::query()->get() as $website) {
            $this->cache->invalidate($website);
        }
    }
}
