<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Location;
use App\Models\Space;
use App\Models\SpaceAssignment;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Demirbaş yönetimi (faz 46): oluştur/güncelle/sil, tahsise bağla/serbest bırak. Demirbaş lokasyona aittir;
 * bir alana yerleşik olabilir; tahsise yalnız aynı lokasyondaki MÜSAİT demirbaş bağlanır. Her yazma audit.
 * Tenant scope yok (Ofisvio envanteri); lokasyon görünürlüğü çağıran tarafta (AuthorizationService::locationIdsWith).
 */
class AssetService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array<int, int>|null  $locationIds  null = tüm lokasyonlar
     * @param  array{status?: string|null, location_id?: int|null, q?: string|null}  $filters
     * @return Collection<int, Asset>
     */
    public function all(?array $locationIds = null, array $filters = []): Collection
    {
        return Asset::query()->with(['location', 'space', 'assignment.company', 'assignment.user'])
            ->when($locationIds !== null, fn (Builder $q) => $q->whereIn('location_id', $locationIds))
            ->when(! empty($filters['location_id']), fn (Builder $q) => $q->where('location_id', (int) $filters['location_id']))
            ->when(! empty($filters['status']), fn (Builder $q) => $q->where('status', (string) $filters['status']))
            ->when(! empty($filters['q']), fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('name', 'like', '%'.$filters['q'].'%')->orWhere('code', 'like', '%'.$filters['q'].'%')->orWhere('serial', 'like', '%'.$filters['q'].'%')))
            ->orderBy('location_id')->orderBy('name')->get();
    }

    public function find(int $id): ?Asset
    {
        return Asset::query()->with(['location', 'space', 'assignment'])->find($id);
    }

    /**
     * Tahsise bağlanabilir demirbaşlar: müsait ve aynı lokasyonda (alan seçilince JS lokasyona göre süzer).
     *
     * @param  array<int, int>|null  $locationIds
     * @return Collection<int, Asset>
     */
    public function assignable(?array $locationIds = null): Collection
    {
        return Asset::query()->where('status', 'available')
            ->when($locationIds !== null, fn (Builder $q) => $q->whereIn('location_id', $locationIds))
            ->orderBy('location_id')->orderBy('name')->get();
    }

    /** @param  array{name: string, code?: string|null, category?: string|null, serial?: string|null, status?: string|null, notes?: string|null, space_id?: int|null}  $data */
    public function create(User $actor, Location $location, array $data): Asset
    {
        $asset = new Asset($this->attributes($location, $data) + ['location_id' => $location->id, 'created_by' => $actor->id]);
        $asset->save();
        $this->audit->record($actor, 'asset.created', 'asset', $asset->id, [], $asset->toArray());

        return $asset;
    }

    /** @param  array<string, mixed>  $data */
    public function update(User $actor, Asset $asset, array $data): Asset
    {
        if ($asset->status === 'assigned') {
            $data['status'] = 'assigned'; // tahsisli demirbaşın durumu yalnız tahsisle değişir; diğer alanlar düzenlenir
        }

        $before = $asset->toArray();
        $asset->fill($this->attributes($asset->location, $data, $asset));
        $asset->save();
        $this->audit->record($actor, 'asset.updated', 'asset', $asset->id, $before, $asset->toArray());

        return $asset;
    }

    public function delete(User $actor, Asset $asset): void
    {
        if ($asset->status === 'assigned') {
            throw new DomainException('Tahsisli demirbaş silinemez; önce tahsisi sonlandırın ya da demirbaşı tahsisten çıkarın.');
        }

        $before = $asset->toArray();
        $asset->delete();
        $this->audit->record($actor, 'asset.deleted', 'asset', $asset->id, $before, []);
    }

    /**
     * Tahsise bağla (işlem içinde çağrılır): id'ler aynı lokasyonda ve müsait olmalı; yoksa DomainException.
     *
     * @param  array<int, int>  $assetIds
     */
    public function attach(User $actor, SpaceAssignment $assignment, Space $space, array $assetIds): void
    {
        $assetIds = array_values(array_unique(array_map('intval', $assetIds)));

        if ($assetIds === []) {
            return;
        }

        $assets = Asset::query()->whereIn('id', $assetIds)->lockForUpdate()->get();

        if ($assets->count() !== count($assetIds)) {
            throw new DomainException('Seçilen demirbaşlardan biri bulunamadı.');
        }

        foreach ($assets as $asset) {
            if ((int) $asset->location_id !== (int) $space->location_id) {
                throw new DomainException($asset->name.' başka lokasyonda; yalnız aynı lokasyonun demirbaşı tahsis edilir.');
            }

            if (! $asset->isAssignable()) {
                throw new DomainException($asset->name.' müsait değil ('.$asset->statusLabel().').');
            }

            $before = $asset->only(['status', 'space_id', 'space_assignment_id']);
            $asset->fill(['status' => 'assigned', 'space_id' => $space->id, 'space_assignment_id' => $assignment->id])->save();
            $this->audit->record($actor, 'asset.assigned', 'asset', $asset->id, $before, $asset->only(['status', 'space_id', 'space_assignment_id']));
        }
    }

    /** Tahsisin demirbaşlarını serbest bırakır (yalnız verilen id'ler ya da hepsi). @param  array<int, int>|null  $onlyIds */
    public function release(?User $actor, SpaceAssignment $assignment, ?array $onlyIds = null): int
    {
        $n = 0;

        foreach (Asset::query()->where('space_assignment_id', $assignment->id)->when($onlyIds !== null, fn (Builder $q) => $q->whereIn('id', $onlyIds))->get() as $asset) {
            $before = $asset->only(['status', 'space_assignment_id']);
            $asset->fill(['status' => 'available', 'space_assignment_id' => null])->save();
            $this->audit->record($actor, 'asset.released', 'asset', $asset->id, $before, $asset->only(['status', 'space_assignment_id']));
            $n++;
        }

        return $n;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(Location $location, array $data, ?Asset $existing = null): array
    {
        $category = (string) ($data['category'] ?? 'furniture');
        $status = (string) ($data['status'] ?? ($existing !== null ? $existing->status : 'available'));

        if (! isset(Asset::CATEGORIES[$category])) {
            throw new DomainException('Geçersiz demirbaş kategorisi.');
        }

        if (! isset(Asset::STATUSES[$status]) || ($status === 'assigned' && $existing?->status !== 'assigned')) {
            throw new DomainException('Geçersiz demirbaş durumu; "tahsisli" yalnız tahsisle verilir.');
        }

        $spaceId = ! empty($data['space_id']) ? (int) $data['space_id'] : null;

        if ($spaceId !== null && ! Space::query()->where('location_id', $location->id)->whereKey($spaceId)->exists()) {
            throw new DomainException('Seçilen alan bu lokasyonda değil.');
        }

        $code = $this->blank($data['code'] ?? null);

        if ($code !== null && Asset::query()->where('code', $code)->when($existing !== null, fn (Builder $q) => $q->whereKeyNot($existing->id))->exists()) {
            throw new DomainException('Bu demirbaş kodu zaten kullanılıyor: '.$code);
        }

        return [
            'name' => trim((string) $data['name']),
            'code' => $code,
            'category' => $category,
            'serial' => $this->blank($data['serial'] ?? null),
            'status' => $status,
            'notes' => $this->blank($data['notes'] ?? null),
            'space_id' => $existing?->status === 'assigned' ? $existing->space_id : $spaceId,
        ];
    }

    private function blank(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
