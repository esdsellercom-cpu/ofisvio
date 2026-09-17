<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Location;
use App\Models\Room;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Booking v1 — odalar, uygunluk motoru ve rezervasyon.
 *
 * Kurallar (uygunluk motoru):
 *   - Oda aktif ve lokasyon aktif olmalı; başlangıç geçmişte olamaz; en fazla
 *     BOOKING_HORIZON_DAYS ileri; süre slot katı, en az bir slot, en fazla max_hours.
 *   - Saat aralığı odanın açık saatleri içinde olmalı.
 *   - ÇAKIŞMA HİÇBİR ZAMAN ATLANMAZ: aynı odada zaman aralığı kesişen onaylı
 *     rezervasyon varsa kayıt reddedilir (işlem + kilit, yarış koşuluna karşı).
 *   - booking.admin_override (operations_admin/super_admin, JIT): açık saat,
 *     ufuk, geçmiş ve pasif oda kurallarını atlar; çakışmayı atlamaz. Kayıt
 *     `overridden` işaretlenir.
 *
 * Tenant sınırı: Booking company_id ile BelongsToTenant taşır. Resepsiyon
 * masası (lokasyon kapsamı) ve personel genel listesi organizasyon bağlamı
 * olmadan çalışır; bu okumalar withoutTenantScope ile yapılır ve yalnızca
 * booking.view (lokasyon/global) taşıyan rotalardan çağrılır (ArchitectureTest
 * allowlist).
 */
class BookingService
{
    public const HORIZON_DAYS = 60;

    public const SLOTS_CACHE_KEY = 'booking:public_slots';

    public function __construct(private readonly TenantContext $context) {}

    /** Rezervasyon alamayan şirket durumları (askıda/fesih). */
    public const BLOCKED_COMPANY_STATUSES = [CompanyStatus::SUSPENDED, CompanyStatus::TERMINATION_PENDING, CompanyStatus::TERMINATED];

    /** @return Collection<int, Room> */
    public function rooms(Location $location, bool $activeOnly = false): Collection
    {
        return Room::query()->where('location_id', $location->id)
            ->when($activeOnly, fn (Builder $q) => $q->where('is_active', true))
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * Rezervasyona açık odalar (aktif oda + aktif lokasyon).
     *
     * @return Collection<int, Room>
     */
    public function bookableRooms(): Collection
    {
        return Room::query()->where('is_active', true)
            ->whereHas('location', fn (Builder $q) => $q->where('is_active', true))
            ->with('location')
            ->get()
            ->sortBy(fn (Room $r) => [$r->location->sort_order, $r->location->name, $r->sort_order, $r->name])
            ->values();
    }

    public function findRoom(int $id): ?Room
    {
        return Room::query()->with('location')->find($id);
    }

    /**
     * @param  array{name: string, kind: string, capacity: int, hourly_rate: int, open_from: string, open_until: string, slot_minutes: int, max_hours: int, is_active?: bool, sort_order?: int, description?: string|null}  $data
     */
    public function createRoom(Location $location, array $data): Room
    {
        $this->assertRoomRules($data);

        $room = new Room(array_merge($data, ['location_id' => $location->id]));
        $room->save();
        Cache::forget(self::SLOTS_CACHE_KEY);

        return $room;
    }

    /** @param  array<string, mixed>  $data */
    public function updateRoom(Room $room, array $data): Room
    {
        $this->assertRoomRules(array_merge($room->toArray(), $data));
        $room->fill($data)->save();
        Cache::forget(self::SLOTS_CACHE_KEY);

        return $room;
    }

    public function deleteRoom(Room $room): void
    {
        if ($room->bookings()->where('status', Booking::STATUS_CONFIRMED)->where('ends_at', '>', Carbon::now())->exists()) {
            throw new DomainException('Odanın gelecek tarihli onaylı rezervasyonu var; önce iptal edin ya da odayı pasife alın.');
        }

        $room->delete();
        Cache::forget(self::SLOTS_CACHE_KEY);
    }

    /** @param  array<string, mixed>  $data */
    private function assertRoomRules(array $data): void
    {
        $from = (string) ($data['open_from'] ?? '');
        $until = (string) ($data['open_until'] ?? '');

        if ($this->minutes($from) >= $this->minutes($until)) {
            throw new DomainException('Açılış saati kapanış saatinden önce olmalı.');
        }

        if (($this->minutes($until) - $this->minutes($from)) % (int) ($data['slot_minutes'] ?? 60) !== 0) {
            throw new DomainException('Açık saat aralığı slot süresinin katı olmalı.');
        }
    }

    /**
     * Bir gün için slot tablosu: başlangıç saati -> dolu mu.
     *
     * @return array<int, array{start: string, end: string, taken: bool, past: bool}>
     */
    public function availability(Room $room, Carbon $day): array
    {
        $dayStart = $day->copy()->startOfDay();
        $taken = Booking::withoutTenantScope() // uygunluk: kimin tuttuğu değil, dolu olup olmadığı (bkz. sınıf başlığı)
            ->where('room_id', $room->id)
            ->where('status', Booking::STATUS_CONFIRMED)
            ->where('starts_at', '<', $dayStart->copy()->addDay())
            ->where('ends_at', '>', $dayStart)
            ->get(['starts_at', 'ends_at']);

        $slots = [];
        $now = Carbon::now();

        for ($m = $this->minutes($room->open_from); $m + $room->slot_minutes <= $this->minutes($room->open_until); $m += $room->slot_minutes) {
            $start = $dayStart->copy()->addMinutes($m);
            $end = $start->copy()->addMinutes($room->slot_minutes);

            $slots[] = [
                'start' => $start->format('H:i'),
                'end' => $end->format('H:i'),
                'taken' => $taken->contains(fn (Booking $b) => $b->starts_at->lessThan($end) && $b->ends_at->greaterThan($start)),
                'past' => $end->lessThanOrEqualTo($now),
            ];
        }

        return $slots;
    }

    /**
     * Rezervasyon oluşturur. Çakışma her koşulda reddedilir (bkz. sınıf başlığı).
     *
     * @param  array{date: string, start: string, hours: int|float, note?: string|null}  $data
     */
    public function book(User $actor, Company $company, Room $room, array $data, bool $override = false): Booking
    {
        $room->loadMissing('location');
        $start = Carbon::parse($data['date'].' '.$data['start']);
        $minutes = (int) round(((float) $data['hours']) * 60);
        $end = $start->copy()->addMinutes($minutes);

        if (in_array($company->status, self::BLOCKED_COMPANY_STATUSES, true)) {
            throw new DomainException('Şirket durumu rezervasyona izin vermiyor.');
        }

        if ($minutes <= 0 || $minutes % $room->slot_minutes !== 0) {
            throw new DomainException('Süre '.$room->slot_minutes.' dakikanın katı olmalı.');
        }

        if ($minutes > $room->max_hours * 60) {
            throw new DomainException('Tek rezervasyon en fazla '.$room->max_hours.' saat olabilir.');
        }

        if (! $override) {
            if (! $room->is_active || ! $room->location->is_active) {
                throw new DomainException('Bu oda şu anda rezervasyona kapalı.');
            }

            if ($start->lessThan(Carbon::now())) {
                throw new DomainException('Geçmiş bir saat için rezervasyon yapılamaz.');
            }

            if ($start->greaterThan(Carbon::today()->addDays(self::HORIZON_DAYS))) {
                throw new DomainException('En fazla '.self::HORIZON_DAYS.' gün ilerisi için rezervasyon alınır.');
            }

            $startMin = $start->hour * 60 + $start->minute;
            $endMin = $startMin + $minutes;

            if ($startMin < $this->minutes($room->open_from) || $endMin > $this->minutes($room->open_until)) {
                throw new DomainException('Oda '.$room->open_from.'–'.$room->open_until.' arasında rezerve edilebilir.');
            }
        }

        return DB::transaction(function () use ($actor, $company, $room, $start, $end, $minutes, $data, $override) {
            // Yarış koşulu: aynı oda için eşzamanlı iki istek aynı aralığı almasın.
            // withoutTenantScope: çakışma kontrolü TÜM şirketlerin rezervasyonuna bakmak zorundadır.
            $conflict = Booking::withoutTenantScope()
                ->where('room_id', $room->id)
                ->where('status', Booking::STATUS_CONFIRMED)
                ->where('starts_at', '<', $end)
                ->where('ends_at', '>', $start)
                ->lockForUpdate()
                ->exists();

            if ($conflict) {
                throw new DomainException('Seçilen saat aralığı dolu; başka bir saat seçin.');
            }

            $booking = new Booking([
                'company_id' => $company->id,
                'room_id' => $room->id,
                'location_id' => $room->location_id,
                'booked_by' => $actor->id,
                'starts_at' => $start,
                'ends_at' => $end,
                'status' => Booking::STATUS_CONFIRMED,
                'total_amount' => (int) round($room->hourly_rate * $minutes / 60),
                'note' => $this->blankToNull($data['note'] ?? null),
                'overridden' => $override,
            ]);
            $booking->save();

            return $booking;
        });
    }

    /**
     * İptal: müşteri (booking.cancel) başlangıçtan en az CANCEL_NOTICE_HOURS önce;
     * override (booking.admin_override) her zaman.
     */
    public const CANCEL_NOTICE_HOURS = 2;

    public function cancel(User $actor, Booking $booking, ?string $reason = null, bool $override = false): Booking
    {
        if (! $booking->isActive()) {
            throw new DomainException('Rezervasyon zaten iptal edilmiş.');
        }

        if (! $override && $booking->starts_at->lessThan(Carbon::now()->addHours(self::CANCEL_NOTICE_HOURS))) {
            throw new DomainException('Başlangıca '.self::CANCEL_NOTICE_HOURS.' saatten az kaldı; iptal için resepsiyonla görüşün.');
        }

        $booking->forceFill([
            'status' => Booking::STATUS_CANCELLED,
            'cancelled_at' => Carbon::now(),
            'cancelled_by' => $actor->id,
            'cancel_reason' => $this->blankToNull($reason),
        ])->save();

        return $booking;
    }

    /**
     * Şirketin rezervasyonları (tenant scope aktif; yaklaşanlar önce).
     *
     * @return LengthAwarePaginator<int, Booking>
     */
    public function forCompany(Company $company, int $perPage = 25): LengthAwarePaginator
    {
        return Booking::query()->where('company_id', $company->id)
            ->with(['room', 'location', 'booker'])
            ->orderByRaw("case when status = 'confirmed' and ends_at > ? then 0 else 1 end", [Carbon::now()])
            ->orderByDesc('starts_at')
            ->paginate($perPage)->withQueryString();
    }

    /**
     * Resepsiyon masası: lokasyonun bir günlük programı. Organizasyon bağlamı yok;
     * yalnızca booking.view (lokasyon/global) rotasından çağrılır.
     *
     * @return Collection<int, Booking>
     */
    public function forLocationDay(Location $location, Carbon $day): Collection
    {
        return Booking::withoutTenantScope()
            ->where('location_id', $location->id)
            ->where('starts_at', '>=', $day->copy()->startOfDay())
            ->where('starts_at', '<', $day->copy()->startOfDay()->addDay())
            ->with(['room', 'booker', 'company' => fn ($q) => $q->withoutGlobalScope(TenantScope::class)]) // şirket adı: masa personelindir, org bağlamı yok
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Personel genel listesi (booking.view global): tüm lokasyonlar, süzgeçli.
     *
     * @param  array{location_id?: int|null, status?: string|null, from?: string|null}  $filters
     * @return LengthAwarePaginator<int, Booking>
     */
    public function paginateAll(array $filters, int $perPage = 50): LengthAwarePaginator
    {
        return Booking::withoutTenantScope()
            ->when($filters['location_id'] ?? null, fn (Builder $q, $id) => $q->where('location_id', (int) $id))
            ->when($filters['status'] ?? null, fn (Builder $q, $s) => $q->where('status', $s))
            ->when($filters['from'] ?? null, fn (Builder $q, $d) => $q->where('starts_at', '>=', Carbon::parse($d)->startOfDay()))
            ->with(['room', 'location', 'booker', 'company' => fn ($q) => $q->withoutGlobalScope(TenantScope::class)])
            ->orderByDesc('starts_at')
            ->paginate($perPage)->withQueryString();
    }

    /** Personel/resepsiyon için rezervasyonu şirket bağlamı olmadan bulur. */
    public function findAny(int $id): ?Booking
    {
        return Booking::withoutTenantScope()->with(['room', 'location', 'company' => fn ($q) => $q->withoutGlobalScope(TenantScope::class)])->find($id);
    }

    /**
     * Resepsiyon masası şirket seçimi: aktif şirketler (tüm organizasyonlar — resepsiyon
     * Ofisvio personelidir, müşteri organizasyonuna üye değildir).
     *
     * @return Collection<int, Company>
     */
    public function companiesForDesk(): Collection
    {
        return Company::withoutTenantScope()->whereNotIn('status', self::BLOCKED_COMPANY_STATUSES)->orderBy('legal_name')->get(['id', 'legal_name']);
    }

    /**
     * Vitrin ön talep aracı için saat çipleri: rezervasyona açık odaların slot
     * başlangıçlarının birleşimi (kodda sabit liste yok; oda yoksa boş).
     *
     * @return array<int, string>
     */
    public function publicSlots(): array
    {
        // Önbellek: vitrin sorgu bütçesi (QueryBudgetTest); oda değişince forgetPublicSlots.
        $cached = Cache::remember(self::SLOTS_CACHE_KEY, 3600, function (): array {
            $slots = [];

            foreach ($this->bookableRooms() as $room) {
                for ($m = $this->minutes($room->open_from); $m + $room->slot_minutes <= $this->minutes($room->open_until); $m += $room->slot_minutes) {
                    $slots[sprintf('%02d:%02d', intdiv($m, 60), $m % 60)] = true;
                }
            }

            ksort($slots);

            return array_keys($slots);
        });

        return array_map('strval', (array) $cached);
    }

    /**
     * Kullanıcının lokasyon kapsamlı aktif rollerinin şubeleri (menü: "X masası").
     * Global booking.view taşıyanlar için boş — onlar genel listeden girer.
     *
     * @return Collection<int, Location>
     */
    public function deskLocationsFor(User $user): Collection
    {
        return $this->context->rememberForRequest("desk:{$user->id}", function () use ($user): Collection {
            $ids = DB::table('user_roles')
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->whereNotNull('location_id')
                ->pluck('location_id')
                ->unique();

            return $ids->isEmpty() ? new Collection : Location::query()->whereIn('id', $ids)->orderBy('name')->get();
        });
    }

    /** Özet sayaçlar (panel başlığı). @return array{today: int, upcoming: int, rooms: int} */
    public function counts(): array
    {
        $now = Carbon::now();

        return [
            'today' => Booking::withoutTenantScope()->where('status', Booking::STATUS_CONFIRMED)->whereBetween('starts_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])->count(),
            'upcoming' => Booking::withoutTenantScope()->where('status', Booking::STATUS_CONFIRMED)->where('starts_at', '>', $now)->count(),
            'rooms' => Room::query()->where('is_active', true)->count(),
        ];
    }

    private function minutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', array_pad(explode(':', $hhmm, 2), 2, 0));

        return $h * 60 + $m;
    }

    private function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
