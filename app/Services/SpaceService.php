<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Location;
use App\Models\LocationMedia;
use App\Models\Scopes\TenantScope;
use App\Models\Space;
use App\Models\SpaceAssignment;
use App\Models\Subscription;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Masa & ofis envanteri (audit P0-2, artifact §3): lokasyon başına sabit/esnek masa ve özel
 * ofis; şirkete (isteğe bağlı üyeye ve üyeliğe) tahsis; doluluk. Envanter geo.edit ile,
 * tahsis space.manage ile; her değişiklik audit.
 *
 * Kurallar: sabit masa/ofis aynı anda tek şirkete; esnek masa alanı kapasite kadar eşzamanlı
 * tahsis alır; pasif alana tahsis yok; askıdaki/fesihli şirkete tahsis yok; tahsis satır
 * kilidiyle yazılır (çift tahsis yarışı yok).
 *
 * withoutTenantScope: genel envanter/doluluk ve tahsis listeleri space.view (GLOBAL) rotasından
 * tüm şirketlere bakar; müşteri tarafı forCompany() tenant scope içinde (ArchitectureTest allowlist).
 */
class SpaceService
{
    public function __construct(private readonly AuditService $audit, private readonly AssetService $assets) {}

    // ---- Envanter -----------------------------------------------------------------

    /** @return Collection<int, Space> */
    public function forLocation(Location $location): Collection
    {
        return $this->baseQuery()->where('location_id', $location->id)->orderBy('kind')->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * @param  array<int, int>|null  $locationIds  null = tüm lokasyonlar; dizi = yalnız bunlar (lokasyon kapsamlı personel)
     * @return Collection<int, Space>
     */
    public function all(?array $locationIds = null): Collection
    {
        return $this->baseQuery()->with(['location', 'activeAssignments.assets', 'cover'])->withCount('assets')->when($locationIds !== null, fn (Builder $q) => $q->whereIn('location_id', $locationIds))
            ->orderBy('location_id')->orderBy('kind')->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * Tahsis listesi (faz 46 — Tahsisler sekmesi): aktif tahsisler, şirket/üye/demirbaş sayısıyla.
     *
     * @param  array<int, int>|null  $locationIds
     * @return Collection<int, SpaceAssignment>
     */
    public function activeAssignments(?array $locationIds = null): Collection
    {
        return SpaceAssignment::withoutTenantScope()->where('status', 'active')
            ->with(['space.location', 'user', 'subscription.plan', 'company' => fn ($c) => $c->withoutGlobalScope(TenantScope::class)])->withCount('assets')
            ->when($locationIds !== null, fn (Builder $q) => $q->whereHas('space', fn (Builder $s) => $s->whereIn('location_id', $locationIds)))
            ->orderBy('ends_on')->orderBy('id')->get();
    }

    /**
     * Tahsis düzenleme (faz 46): bitiş, üye, not, demirbaş kümesi (eklenen bağlanır, çıkarılan serbest kalır).
     *
     * @param  array{ends_on?: string|null, user_id?: int|string|null, note?: string|null, asset_ids?: array<int, int|string>|null}  $data
     */
    public function updateAssignment(User $actor, SpaceAssignment $assignment, array $data): SpaceAssignment
    {
        if (! $assignment->isActive()) {
            throw new DomainException('Yalnız aktif tahsis düzenlenir.');
        }

        $ends = ! empty($data['ends_on']) ? Carbon::parse((string) $data['ends_on'])->startOfDay() : null;

        if ($ends !== null && $ends->lt($assignment->starts_on)) {
            throw new DomainException('Bitiş başlangıçtan önce olamaz.');
        }

        return DB::transaction(function () use ($actor, $assignment, $data, $ends) {
            $before = $assignment->only(['ends_on', 'user_id', 'note']);
            $assignment->fill([
                'ends_on' => $ends?->toDateString(),
                'user_id' => ! empty($data['user_id']) ? (int) $data['user_id'] : null,
                'note' => $this->blank($data['note'] ?? null),
            ])->save();

            if (array_key_exists('asset_ids', $data)) {
                $wanted = array_values(array_unique(array_map('intval', (array) ($data['asset_ids'] ?? []))));
                $current = $assignment->assets()->pluck('id')->map(fn ($id) => (int) $id)->all();
                $this->assets->release($actor, $assignment, array_values(array_diff($current, $wanted)));
                $this->assets->attach($actor, $assignment, $assignment->space, array_values(array_diff($wanted, $current)));
            }

            $this->audit->record($actor, 'space.assignment_updated', 'space_assignment', $assignment->id, $before, $assignment->only(['ends_on', 'user_id', 'note']));

            return $assignment;
        });
    }

    public function find(int $id): ?Space
    {
        return $this->baseQuery()->with('location')->find($id);
    }

    /** @param  array{kind: string, name: string, floor?: string|null, zone?: string|null, capacity?: int|null, monthly_price?: int|null, is_active?: bool, sort_order?: int|null, notes?: string|null}  $data  monthly_price kuruş */
    public function create(User $actor, Location $location, array $data): Space
    {
        $space = new Space($this->attributes($data) + ['location_id' => $location->id]);
        $this->assertUniqueName($space);
        $this->assertCoverInGallery($location, $space->cover_media_id);
        $space->save();
        $this->audit->record($actor, 'space.created', 'space', $space->id, [], $space->toArray());

        return $space;
    }

    /** @param  array<string, mixed>  $data */
    public function update(User $actor, Space $space, array $data): Space
    {
        $before = $space->toArray();
        $space->fill($this->attributes($data));
        $this->assertUniqueName($space);
        $this->assertCoverInGallery($space->location, $space->cover_media_id);

        if (! $space->is_active && $space->occupied() > 0) {
            throw new DomainException('Aktif tahsisi olan alan pasife alınamaz; önce tahsisi sonlandırın.');
        }

        $space->save();
        $this->audit->record($actor, 'space.updated', 'space', $space->id, $before, $space->toArray());

        return $space;
    }

    public function delete(User $actor, Space $space): void
    {
        if ($space->assignments()->withoutGlobalScope(TenantScope::class)->exists()) {
            throw new DomainException('Tahsis geçmişi olan alan silinemez; pasife alın.');
        }

        $before = $space->toArray();
        $space->delete();
        $this->audit->record($actor, 'space.deleted', 'space', $space->id, $before, []);
    }

    // ---- Tahsis -------------------------------------------------------------------

    /**
     * @param  array{starts_on: string, ends_on?: string|null, subscription_id?: int|string|null, user_id?: int|string|null, note?: string|null, asset_ids?: array<int, int|string>|null}  $data
     */
    public function assign(User $actor, Space $space, Company $company, array $data): SpaceAssignment
    {
        if (! $space->is_active) {
            throw new DomainException('Pasif alana tahsis yapılamaz.');
        }

        if ($space->isUnderMaintenance()) {
            throw new DomainException('Alan bakımda'.($space->maintenance_note ? ' ('.$space->maintenance_note.')' : '').'; bakım bitişi '.$space->maintenance_until?->format('d.m.Y').'.');
        }

        if (in_array($company->status, BookingService::BLOCKED_COMPANY_STATUSES, true)) {
            throw new DomainException('Askıda ya da fesih sürecindeki şirkete tahsis yapılamaz.');
        }

        $subscription = ! empty($data['subscription_id']) ? Subscription::withoutTenantScope()->where('company_id', $company->id)->find((int) $data['subscription_id']) : null;

        if (! empty($data['subscription_id']) && $subscription === null) {
            throw new DomainException('Üyelik bu şirkete ait değil.');
        }

        $starts = Carbon::parse($data['starts_on'])->startOfDay();
        $ends = ! empty($data['ends_on']) ? Carbon::parse($data['ends_on'])->startOfDay() : null;

        if ($ends !== null && $ends->lt($starts)) {
            throw new DomainException('Bitiş başlangıçtan önce olamaz.');
        }

        return DB::transaction(function () use ($actor, $space, $company, $subscription, $data, $starts, $ends) {
            // Çift tahsis yarışı: önce alan satırı kilitlenir (eşzamanlı tahsisler sıraya girer; aktif tahsis
            // henüz yokken yalnız tahsis satırlarını kilitlemek phantom read'e açıktır), sonra kapasite sayılır.
            Space::query()->whereKey($space->id)->lockForUpdate()->first();
            $active = SpaceAssignment::withoutTenantScope()->where('space_id', $space->id)->where('status', 'active')->lockForUpdate()->count();

            if ($active >= $space->slots()) {
                throw new DomainException($space->kind === 'desk_flex' ? 'Esnek masa alanı dolu (kapasite '.$space->slots().').' : 'Alan zaten tahsisli; önce mevcut tahsisi sonlandırın.');
            }

            $assignment = new SpaceAssignment([
                'space_id' => $space->id,
                'company_id' => $company->id,
                'subscription_id' => $subscription?->id,
                'user_id' => ! empty($data['user_id']) ? (int) $data['user_id'] : null,
                'status' => 'active',
                'starts_on' => $starts->toDateString(),
                'ends_on' => $ends?->toDateString(),
                'note' => $this->blank($data['note'] ?? null),
                'created_by' => $actor->id,
            ]);
            $assignment->save();
            $this->audit->record($actor, 'space.assigned', 'space_assignment', $assignment->id, [], $assignment->toArray());
            // Demirbaşlar (faz 46): aynı lokasyondaki müsait demirbaşlar tahsisle birlikte verilir.
            $this->assets->attach($actor, $assignment, $space, array_map('intval', (array) ($data['asset_ids'] ?? [])));

            return $assignment;
        });
    }

    public function end(User $actor, SpaceAssignment $assignment): SpaceAssignment
    {
        if (! $assignment->isActive()) {
            throw new DomainException('Tahsis zaten sona ermiş.');
        }

        $before = $assignment->toArray();
        $assignment->fill(['status' => 'ended', 'ended_by' => $actor->id, 'ended_at' => Carbon::now(), 'ends_on' => $assignment->ends_on?->lt(Carbon::today()) ? $assignment->ends_on : Carbon::today()->toDateString()])->save();
        $this->assets->release($actor, $assignment);
        $this->audit->record($actor, 'space.assignment_ended', 'space_assignment', $assignment->id, $before, $assignment->toArray());

        return $assignment;
    }

    /** Zamanlayıcı: bitiş tarihi geçmiş aktif tahsisler sona erer. */
    public function endExpired(): int
    {
        $n = 0;

        foreach (SpaceAssignment::withoutTenantScope()->where('status', 'active')->whereNotNull('ends_on')->whereDate('ends_on', '<', Carbon::today()->toDateString())->get() as $assignment) {
            $before = $assignment->toArray();
            $assignment->fill(['status' => 'ended', 'ended_at' => Carbon::now()])->save();
            $this->assets->release(null, $assignment);
            $this->audit->record(null, 'space.assignment_ended', 'space_assignment', $assignment->id, $before, $assignment->toArray());
            $n++;
        }

        return $n;
    }

    public function findAssignment(int $id): ?SpaceAssignment
    {
        return SpaceAssignment::withoutTenantScope()->with(['space.location', 'user', 'subscription.plan', 'assets', 'company' => fn ($c) => $c->withoutGlobalScope(TenantScope::class)])->find($id);
    }

    /**
     * Alanın tahsis geçmişi (personel).
     *
     * @return Collection<int, SpaceAssignment>
     */
    public function assignmentsOf(Space $space): Collection
    {
        return SpaceAssignment::withoutTenantScope()->where('space_id', $space->id)->with(['user', 'subscription.plan', 'company' => fn ($c) => $c->withoutGlobalScope(TenantScope::class)])
            ->orderByRaw("case when status = 'active' then 0 else 1 end")->orderByDesc('starts_on')->get();
    }

    /**
     * Müşteri tarafı (tenant scope içinde): şirketin alanları.
     *
     * @return Collection<int, SpaceAssignment>
     */
    public function forCompany(Company $company): Collection
    {
        return SpaceAssignment::query()->where('company_id', $company->id)->with(['space.location', 'user'])->orderByRaw("case when status = 'active' then 0 else 1 end")->orderByDesc('starts_on')->get();
    }

    // ---- Doluluk ------------------------------------------------------------------

    /**
     * Tür başına envanter/doluluk (tüm lokasyonlar, tek lokasyon ya da lokasyon kapsamlı personelin lokasyonları).
     *
     * @param  array<int, int>|null  $locationIds
     * @return array{total: int, slots: int, occupied: int, rate: float, by_kind: array<string, array{label: string, total: int, slots: int, occupied: int}>, ending_30d: int}
     */
    public function occupancy(?Location $location = null, ?array $locationIds = null): array
    {
        $spaces = $this->baseQuery()->where('is_active', true)->when($location !== null, fn (Builder $q) => $q->where('location_id', $location->id))
            ->when($locationIds !== null, fn (Builder $q) => $q->whereIn('location_id', $locationIds))->get();
        $byKind = [];

        foreach (Space::KINDS as $kind => $label) {
            $group = $spaces->where('kind', $kind);
            $byKind[$kind] = ['label' => $label, 'total' => $group->count(), 'slots' => (int) $group->sum(fn (Space $s) => $s->slots()), 'occupied' => (int) $group->sum(fn (Space $s) => min($s->slots(), $s->occupied()))];
        }

        $slots = (int) array_sum(array_column($byKind, 'slots'));
        $occupied = (int) array_sum(array_column($byKind, 'occupied'));

        return [
            'total' => $spaces->count(),
            'slots' => $slots,
            'occupied' => $occupied,
            'rate' => $slots > 0 ? round($occupied / $slots * 100, 1) : 0.0,
            'by_kind' => $byKind,
            'ending_30d' => SpaceAssignment::withoutTenantScope()->where('status', 'active')->whereNotNull('ends_on')
                ->when($location !== null, fn (Builder $q) => $q->whereHas('space', fn (Builder $s) => $s->where('location_id', $location->id)))
                ->when($locationIds !== null, fn (Builder $q) => $q->whereHas('space', fn (Builder $s) => $s->whereIn('location_id', $locationIds)))
                ->whereDate('ends_on', '<=', Carbon::today()->addDays(30)->toDateString())->count(),
        ];
    }

    /**
     * Lokasyon başına özet (alanlar ekranı); $locationIds null = hepsi.
     *
     * @param  array<int, int>|null  $locationIds
     * @return array<int, array{location: Location, occupancy: array{total: int, slots: int, occupied: int, rate: float, by_kind: array<string, array{label: string, total: int, slots: int, occupied: int}>, ending_30d: int}}>
     */
    public function byLocation(?array $locationIds = null): array
    {
        $out = [];

        foreach (Location::query()->when($locationIds !== null, fn (Builder $q) => $q->whereIn('id', $locationIds))->orderBy('city')->orderBy('sort_order')->orderBy('name')->get() as $location) {
            $summary = $this->occupancy($location);

            if ($summary['total'] > 0) {
                $out[] = ['location' => $location, 'occupancy' => $summary];
            }
        }

        return $out;
    }

    /** @return Builder<Space> */
    private function baseQuery(): Builder
    {
        return Space::query()->withCount(['assignments as active_assignments_count' => fn (Builder $q) => $q->withoutGlobalScope(TenantScope::class)->where('status', 'active')]);
    }

    /** @param  array<string, mixed>  $data  @return array<string, mixed> */
    private function attributes(array $data): array
    {
        $kind = (string) ($data['kind'] ?? '');

        if (! isset(Space::KINDS[$kind])) {
            throw new DomainException('Geçersiz alan türü.');
        }

        return [
            'kind' => $kind,
            'name' => trim((string) $data['name']),
            'code' => $this->blank($data['code'] ?? null),
            'floor' => $this->blank($data['floor'] ?? null),
            'zone' => $this->blank($data['zone'] ?? null),
            'capacity' => max(1, (int) ($data['capacity'] ?? 1)),
            'monthly_price' => max(0, (int) ($data['monthly_price'] ?? 0)),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'amenities' => self::amenities($data['amenities'] ?? null),
            'cover_media_id' => ! empty($data['cover_media_id']) ? (int) $data['cover_media_id'] : null,
            'maintenance_until' => $this->maintenanceUntil($data['maintenance_until'] ?? null),
            'maintenance_note' => $this->blank($data['maintenance_note'] ?? null),
            'notes' => $this->blank($data['notes'] ?? null),
        ];
    }

    private function assertUniqueName(Space $space): void
    {
        $exists = Space::query()->where('location_id', $space->location_id)->where('name', $space->name)->when($space->exists, fn (Builder $q) => $q->where('id', '!=', $space->id))->exists();

        if ($exists) {
            throw new DomainException('Bu lokasyonda aynı adlı alan var.');
        }

        if ($space->code !== null && Space::query()->where('code', $space->code)->when($space->exists, fn (Builder $q) => $q->where('id', '!=', $space->id))->exists()) {
            throw new DomainException('Bu envanter kodu zaten kullanılıyor: '.$space->code);
        }
    }

    /** Olanak listesi: virgül/satır ayrımlı → en çok 20 madde, 40 karakter, tekil. @return array<int, string>|null */
    public static function amenities(mixed $raw): ?array
    {
        $items = is_array($raw) ? $raw : (preg_split('/[,\r\n]+/', (string) $raw) ?: []);
        $clean = [];

        foreach ($items as $item) {
            $item = trim((string) $item);

            if ($item !== '' && ! in_array($item, $clean, true)) {
                $clean[] = mb_substr($item, 0, 40);
            }
        }

        $clean = array_slice($clean, 0, 20);

        return $clean === [] ? null : $clean;
    }

    private function maintenanceUntil(mixed $raw): ?string
    {
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        $date = Carbon::parse((string) $raw)->startOfDay();

        if ($date->lt(Carbon::today())) {
            throw new DomainException('Bakım bitiş tarihi bugünden önce olamaz; bakımı kaldırmak için alanı boşaltın.');
        }

        return $date->toDateString();
    }

    /** Kapak görseli lokasyon galerisinden olmalı. */
    private function assertCoverInGallery(Location $location, ?int $mediaId): void
    {
        if ($mediaId !== null && ! LocationMedia::query()->where('location_id', $location->id)->where('media_id', $mediaId)->exists()) {
            throw new DomainException('Kapak görseli bu lokasyonun galerisinden seçilmeli.');
        }
    }

    private function blank(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
