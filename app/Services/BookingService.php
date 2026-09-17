<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\CompanyStatus;
use App\Events\BookingStatusChanged;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\Company;
use App\Models\Location;
use App\Models\Room;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Models\Website;
use App\Settings\SettingsRegistry;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Booking Engine v2 (master prompt §4–11): odalar, uygunluk motoru, talep → onay →
 * teyit durum makinesi, iptal/red/giriş/tamamlama, süre dolumu, referans numarası.
 *
 * Uygunluk motoru (§11) — sırayla: oda/lokasyon aktif → önceden/ufuk (ayar) → açık
 * saat → slot katı / üst sınır → şirket durumu → ÇAKIŞMA (onaylı + bekleyen tutmalar,
 * tampon dahil; işlem + lockForUpdate). Çakışma hiçbir koşulda atlanmaz;
 * booking.admin_override (JIT) yalnız takvim kurallarını atlar.
 *
 * Onay politikası (§8): ayar booking.auto_confirm (lokasyon üzerine yazabilir) —
 * kapalıysa PENDING_APPROVAL + expires_at; açıksa CONFIRMED. Durum yalnız
 * transition() ile değişir; her geçiş booking_status_history + audit + domain olayı
 * (commit sonrası) üretir; frontend durum gönderemez.
 *
 * Tenant sınırı: Booking company_id ile BelongsToTenant taşır; vitrin talebi
 * company_id NULL. Personel/masa okumaları withoutTenantScope (ArchitectureTest
 * allowlist) ve yalnız booking.view (lokasyon/global) rotalarından.
 */
class BookingService
{
    public const SLOTS_CACHE_KEY = 'booking:public_slots';

    /** Rezervasyon alamayan şirket durumları (askıda/fesih). */
    public const BLOCKED_COMPANY_STATUSES = [CompanyStatus::SUSPENDED, CompanyStatus::TERMINATION_PENDING, CompanyStatus::TERMINATED];

    /** Sekmeler (§9). */
    public const TABS = ['all' => 'Tümü', 'pending' => 'Onay bekleyen', 'confirmed' => 'Onaylananlar', 'today' => 'Bugün', 'upcoming' => 'Yaklaşan', 'completed' => 'Tamamlanan', 'cancelled' => 'İptal', 'rejected' => 'Reddedilen'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
        private readonly ContentCache $contentCache,
    ) {}

    // ---- Odalar --------------------------------------------------------------

    /** @return Collection<int, Room> */
    public function rooms(Location $location, bool $activeOnly = false): Collection
    {
        return Room::query()->where('location_id', $location->id)
            ->when($activeOnly, fn (Builder $q) => $q->where('is_active', true))
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * Rezervasyona açık odalar (aktif oda + aktif lokasyon); vitrin için yayındaki lokasyon.
     *
     * @return Collection<int, Room>
     */
    public function bookableRooms(bool $publishedOnly = false, ?int $locationId = null): Collection
    {
        return Room::query()->where('is_active', true)
            ->whereHas('location', fn (Builder $q) => $q->where('is_active', true)->when($publishedOnly, fn (Builder $w) => $w->where('is_published', true)))
            ->when($locationId !== null, fn (Builder $q) => $q->where('location_id', $locationId))
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
    public function createRoom(?User $actor, Location $location, array $data): Room
    {
        $this->assertRoomRules($data);

        $room = new Room(array_merge($data, ['location_id' => $location->id]));
        $room->save();
        $this->bumpSiteCaches();
        $this->audit->record($actor, 'room.created', 'room', $room->id, [], $room->toArray());

        return $room;
    }

    /** @param  array<string, mixed>  $data */
    public function updateRoom(?User $actor, Room $room, array $data): Room
    {
        $this->assertRoomRules(array_merge($room->toArray(), $data));
        $before = $room->toArray();
        $room->fill($data)->save();
        $this->bumpSiteCaches();
        $this->audit->record($actor, 'room.updated', 'room', $room->id, $before, $room->toArray());

        return $room;
    }

    public function deleteRoom(?User $actor, Room $room): void
    {
        if ($room->bookings()->whereIn('status', BookingStatus::blockingValues())->where('ends_at', '>', Carbon::now())->exists()) {
            throw new DomainException('Odanın gelecek tarihli aktif rezervasyonu var; önce iptal edin ya da odayı pasife alın.');
        }

        $before = $room->toArray();
        $room->delete();
        $this->bumpSiteCaches();
        $this->audit->record($actor, 'room.deleted', 'room', $room->id, $before, []);
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

    // ---- Politika (ayarlardan; lokasyon üzerine yazabilir) ---------------------

    /** @return array{auto_confirm: bool, min_advance_hours: int, max_advance_days: int, buffer_minutes: int, cancel_notice_hours: int, expires_hours: int, sla: string} */
    public function policy(?int $locationId): array
    {
        $ctx = $locationId !== null ? ['location_id' => $locationId] : [];

        return [
            'auto_confirm' => $this->settings->bool('booking.auto_confirm', $ctx),
            'min_advance_hours' => $this->settings->int('booking.min_advance_hours', $ctx),
            'max_advance_days' => $this->settings->int('booking.max_advance_days', $ctx),
            'buffer_minutes' => $this->settings->int('booking.buffer_minutes', $ctx),
            'cancel_notice_hours' => $this->settings->int('booking.cancel_notice_hours', $ctx),
            'expires_hours' => $this->settings->int('booking.request_expires_hours'),
            'sla' => $this->settings->string('booking.confirmation_sla', $ctx),
        ];
    }

    /** Vitrin rozeti: teyit taahhüdü ayarından türetilen metin (§8). */
    public function confirmationBadge(?int $locationId = null): string
    {
        $policy = $this->policy($locationId);

        if ($policy['auto_confirm']) {
            return 'anında teyit';
        }

        $options = SettingsRegistry::definition('booking.confirmation_sla')['options'] ?? [];

        return mb_strtolower((string) ($options[$policy['sla']] ?? 'aynı gün teyit'));
    }

    // ---- Uygunluk ------------------------------------------------------------

    /**
     * Bir gün için slot tablosu: başlangıç saati → dolu / çok yakın-geçmiş.
     *
     * @return array<int, array{start: string, end: string, taken: bool, past: bool}>
     */
    public function availability(Room $room, Carbon $day): array
    {
        $dayStart = $day->copy()->startOfDay();
        $policy = $this->policy($room->location_id);
        $buffer = $policy['buffer_minutes'];
        $earliest = Carbon::now()->addHours($policy['min_advance_hours']);
        // Uygunluk: kimin tuttuğu değil, dolu olup olmadığı; bekleyen tutmalar da meşgul sayılır (bkz. sınıf başlığı).
        $taken = Booking::withoutTenantScope()
            ->where('room_id', $room->id)
            ->whereIn('status', BookingStatus::blockingValues())
            ->where('starts_at', '<', $dayStart->copy()->addDay()->addMinutes($buffer))
            ->where('ends_at', '>', $dayStart->copy()->subMinutes($buffer))
            ->get(['starts_at', 'ends_at']);

        $slots = [];

        for ($m = $this->minutes($room->open_from); $m + $room->slot_minutes <= $this->minutes($room->open_until); $m += $room->slot_minutes) {
            $start = $dayStart->copy()->addMinutes($m);
            $end = $start->copy()->addMinutes($room->slot_minutes);

            $slots[] = [
                'start' => $start->format('H:i'),
                'end' => $end->format('H:i'),
                'taken' => $taken->contains(fn (Booking $b) => $b->starts_at->copy()->subMinutes($buffer)->lessThan($end) && $b->ends_at->copy()->addMinutes($buffer)->greaterThan($start)),
                'past' => $start->lessThan($earliest),
            ];
        }

        return $slots;
    }

    // ---- Talep / rezervasyon oluşturma ------------------------------------------

    /**
     * Rezervasyon (müşteri paneli, masa) ya da talep (vitrin: $company null, müşteri alanları dolu).
     * Çakışma her koşulda reddedilir. Onay politikası durumu belirler.
     *
     * @param  array{date: string, start: string, hours: int|float, note?: string|null, participants?: int|null, customer_name?: string|null, customer_email?: string|null, customer_phone?: string|null, company_name?: string|null, source?: string|null, consent_ip?: string|null}  $data
     */
    public function book(?User $actor, ?Company $company, Room $room, array $data, bool $override = false): Booking
    {
        $room->loadMissing('location');
        $policy = $this->policy($room->location_id);
        $start = Carbon::parse($data['date'].' '.$data['start']);
        $minutes = (int) round(((float) $data['hours']) * 60);
        $end = $start->copy()->addMinutes($minutes);

        if ($company !== null && in_array($company->status, self::BLOCKED_COMPANY_STATUSES, true)) {
            throw new DomainException('Şirket durumu rezervasyona izin vermiyor.');
        }

        if ($company === null && (empty($data['customer_name']) || empty($data['customer_email']) || empty($data['customer_phone']))) {
            throw new DomainException('Ad, e-posta ve telefon zorunlu.');
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

            if ($start->lessThan(Carbon::now()->addHours($policy['min_advance_hours']))) {
                throw new DomainException($policy['min_advance_hours'] > 0 ? 'Başlangıca en az '.$policy['min_advance_hours'].' saat kala talep alınır.' : 'Geçmiş bir saat için rezervasyon yapılamaz.');
            }

            if ($start->greaterThan(Carbon::today()->addDays($policy['max_advance_days']))) {
                throw new DomainException('En fazla '.$policy['max_advance_days'].' gün ilerisi için rezervasyon alınır.');
            }

            $startMin = $start->hour * 60 + $start->minute;

            if ($startMin < $this->minutes($room->open_from) || $startMin + $minutes > $this->minutes($room->open_until)) {
                throw new DomainException('Oda '.$room->open_from.'–'.$room->open_until.' arasında rezerve edilebilir.');
            }
        }

        $booking = DB::transaction(function () use ($actor, $company, $room, $start, $end, $minutes, $data, $override, $policy) {
            $this->assertNoConflict($room, $start, $end, $policy['buffer_minutes']);

            $initial = $policy['auto_confirm'] || $override ? BookingStatus::CONFIRMED : BookingStatus::PENDING_APPROVAL;

            $booking = new Booking([
                'uuid' => (string) Str::uuid(),
                'company_id' => $company?->id,
                'room_id' => $room->id,
                'location_id' => $room->location_id,
                'booked_by' => $actor?->id,
                'customer_name' => $this->blankToNull($data['customer_name'] ?? null),
                'customer_email' => $this->blankToNull($data['customer_email'] ?? null),
                'customer_phone' => $this->blankToNull($data['customer_phone'] ?? null),
                'company_name' => $this->blankToNull($data['company_name'] ?? null),
                'starts_at' => $start,
                'ends_at' => $end,
                'participant_count' => max(1, (int) ($data['participants'] ?? 1)),
                'status' => BookingStatus::REQUESTED,
                'source' => (string) ($data['source'] ?? 'panel'),
                'approval_required' => $initial === BookingStatus::PENDING_APPROVAL,
                'expires_at' => $initial === BookingStatus::PENDING_APPROVAL ? Carbon::now()->addHours($policy['expires_hours']) : null,
                'consented_at' => $company === null ? Carbon::now() : null,
                'consent_ip' => $this->blankToNull($data['consent_ip'] ?? null),
                'total_amount' => (int) round($room->hourly_rate * $minutes / 60),
                'note' => $this->blankToNull($data['note'] ?? null),
                'overridden' => $override,
            ]);
            $booking->save();
            $booking->reference = $this->reference($booking);
            $booking->save();

            $this->history($booking, null, BookingStatus::REQUESTED, $actor, null);
            $this->audit->record($actor, 'booking.created', 'booking', $booking->id, [], $booking->only(['reference', 'room_id', 'starts_at', 'ends_at', 'status', 'source', 'total_amount']), $company?->organization_id);

            if ($initial === BookingStatus::CONFIRMED) {
                $booking->forceFill(['approved_by' => $override ? $actor?->id : null, 'approved_at' => Carbon::now()]);
            }

            $this->apply($booking, $initial, $actor, $override ? 'kural dışı (JIT)' : ($policy['auto_confirm'] ? 'otomatik onay' : 'onay politikası'));

            return $booking;
        });

        // Olay commit'ten sonra: bildirim kuyruğu iş kaydını asla geri almaz (§19).
        $initial = $booking->status;
        DB::afterCommit(fn () => event(new BookingStatusChanged($booking, null, $initial)));

        return $booking;
    }

    /** Aynı oda + kesişen aralık (tampon dahil) → reddet. İşlem içinde çağrılır. */
    private function assertNoConflict(Room $room, CarbonInterface $start, CarbonInterface $end, int $buffer, ?int $ignoreId = null): void
    {
        // withoutTenantScope: çakışma kontrolü TÜM şirketlerin rezervasyonuna bakmak zorundadır.
        $conflict = Booking::withoutTenantScope()
            ->where('room_id', $room->id)
            ->whereIn('status', BookingStatus::blockingValues())
            ->when($ignoreId !== null, fn (Builder $q) => $q->where('id', '!=', $ignoreId))
            ->where('starts_at', '<', $end->copy()->addMinutes($buffer))
            ->where('ends_at', '>', $start->copy()->subMinutes($buffer))
            ->lockForUpdate()
            ->exists();

        if ($conflict) {
            throw new DomainException('Seçilen saat aralığı dolu; başka bir saat seçin.');
        }
    }

    /** OV-2026-000124: önek ayardan, yıl + sıfır dolgulu id. */
    private function reference(Booking $booking): string
    {
        return sprintf('%s-%s-%06d', $this->settings->string('booking.reference_prefix'), Carbon::now()->format('Y'), $booking->id);
    }

    // ---- Durum makinesi -------------------------------------------------------

    /**
     * Tek geçiş kapısı: izinli mi (enum), tarih alanları, geçmiş, audit, olay (commit sonrası).
     */
    public function transition(Booking $booking, BookingStatus $to, ?User $actor, ?string $reason = null): Booking
    {
        $from = $booking->status;

        if (! $from->canTransitionTo($to)) {
            throw new DomainException("'{$from->label()}' durumundan '{$to->label()}' durumuna geçilemez.");
        }

        DB::transaction(function () use ($booking, $to, $actor, $reason) {
            match ($to) {
                BookingStatus::CONFIRMED => $booking->forceFill(['approved_by' => $actor?->id, 'approved_at' => Carbon::now(), 'expires_at' => null]),
                BookingStatus::REJECTED => $booking->forceFill(['rejected_reason' => $this->blankToNull($reason)]),
                BookingStatus::CANCELLED => $booking->forceFill(['cancelled_at' => Carbon::now(), 'cancelled_by' => $actor?->id, 'cancel_reason' => $this->blankToNull($reason)]),
                BookingStatus::CHECKED_IN => $booking->forceFill(['checked_in_at' => Carbon::now()]),
                BookingStatus::COMPLETED => $booking->forceFill(['completed_at' => Carbon::now()]),
                default => null,
            };

            $this->apply($booking, $to, $actor, $reason);
        });

        DB::afterCommit(fn () => event(new BookingStatusChanged($booking, $from, $to, $reason)));

        return $booking;
    }

    private function apply(Booking $booking, BookingStatus $to, ?User $actor, ?string $reason): void
    {
        $from = $booking->status;
        $booking->status = $to;
        $booking->save();
        $this->history($booking, $from, $to, $actor, $reason);
        $this->audit->record($actor, 'booking.status_changed', 'booking', $booking->id, ['status' => $from->value], ['status' => $to->value, 'reason' => $reason], $booking->company?->organization_id);
    }

    private function history(Booking $booking, ?BookingStatus $from, BookingStatus $to, ?User $actor, ?string $reason): void
    {
        BookingStatusHistory::create(['booking_id' => $booking->id, 'from_status' => $from?->value, 'to_status' => $to->value, 'actor_id' => $actor?->id, 'reason' => $this->blankToNull($reason), 'created_at' => Carbon::now()]);
    }

    public function approve(User $actor, Booking $booking, ?string $note = null): Booking
    {
        // Onay anında çakışma yeniden doğrulanır (kural dışı açılmış bir kayıt araya girmiş olabilir).
        DB::transaction(fn () => $this->assertNoConflict($booking->room, $booking->starts_at, $booking->ends_at, $this->policy($booking->location_id)['buffer_minutes'], $booking->id));

        return $this->transition($booking, BookingStatus::CONFIRMED, $actor, $note);
    }

    public function reject(User $actor, Booking $booking, string $reason): Booking
    {
        return $this->transition($booking, BookingStatus::REJECTED, $actor, $reason);
    }

    /**
     * İptal: müşteri başlangıçtan en az cancel_notice_hours önce; override/yönetim her zaman.
     */
    public function cancel(?User $actor, Booking $booking, ?string $reason = null, bool $override = false): Booking
    {
        if (! $booking->isActive()) {
            throw new DomainException('Rezervasyon aktif değil ('.$booking->statusLabel().').');
        }

        $notice = $this->policy($booking->location_id)['cancel_notice_hours'];

        if (! $override && $booking->starts_at->lessThan(Carbon::now()->addHours($notice))) {
            throw new DomainException('Başlangıca '.$notice.' saatten az kaldı; iptal için resepsiyonla görüşün.');
        }

        return $this->transition($booking, BookingStatus::CANCELLED, $actor, $reason);
    }

    public function checkIn(User $actor, Booking $booking): Booking
    {
        return $this->transition($booking, BookingStatus::CHECKED_IN, $actor);
    }

    public function complete(User $actor, Booking $booking): Booking
    {
        return $this->transition($booking, BookingStatus::COMPLETED, $actor);
    }

    public function noShow(User $actor, Booking $booking, ?string $note = null): Booking
    {
        if ($booking->starts_at->greaterThan(Carbon::now())) {
            throw new DomainException('Başlangıç saati gelmeden "gelmedi" işaretlenemez.');
        }

        return $this->transition($booking, BookingStatus::NO_SHOW, $actor, $note);
    }

    /** Yeniden planlama / oda değiştirme (booking.manage): aynı kurallar + çakışma; audit. */
    public function reschedule(User $actor, Booking $booking, Carbon $start, int $minutes, ?Room $room = null): Booking
    {
        if (! $booking->isActive()) {
            throw new DomainException('Yalnız aktif rezervasyon yeniden planlanır.');
        }

        $room ??= $booking->room;
        $end = $start->copy()->addMinutes($minutes);

        if ($minutes <= 0 || $minutes % $room->slot_minutes !== 0 || $minutes > $room->max_hours * 60) {
            throw new DomainException('Süre '.$room->slot_minutes.' dakikanın katı ve en fazla '.$room->max_hours.' saat olmalı.');
        }

        $before = $booking->only(['room_id', 'starts_at', 'ends_at', 'total_amount']);

        DB::transaction(function () use ($booking, $room, $start, $end, $minutes) {
            $this->assertNoConflict($room, $start, $end, $this->policy($room->location_id)['buffer_minutes'], $booking->id);
            $booking->forceFill(['room_id' => $room->id, 'location_id' => $room->location_id, 'starts_at' => $start, 'ends_at' => $end, 'total_amount' => (int) round($room->hourly_rate * $minutes / 60)])->save();
        });

        $this->audit->record($actor, 'booking.rescheduled', 'booking', $booking->id, $before, $booking->only(['room_id', 'starts_at', 'ends_at', 'total_amount']));

        return $booking;
    }

    public function setInternalNote(User $actor, Booking $booking, ?string $note): Booking
    {
        $before = ['internal_note' => $booking->internal_note];
        $booking->forceFill(['internal_note' => $this->blankToNull($note)])->save();
        $this->audit->record($actor, 'booking.note_changed', 'booking', $booking->id, $before, ['internal_note' => $booking->internal_note]);

        return $booking;
    }

    /** Zamanlayıcı: onaysız talepler süresi dolunca EXPIRED (saat serbest kalır). */
    public function expireStale(): int
    {
        $n = 0;

        foreach (Booking::withoutTenantScope()->where('status', BookingStatus::PENDING_APPROVAL->value)->whereNotNull('expires_at')->where('expires_at', '<=', Carbon::now())->get() as $booking) {
            $this->transition($booking, BookingStatus::EXPIRED, null, 'onay süresi doldu');
            $n++;
        }

        return $n;
    }

    // ---- Okumalar --------------------------------------------------------------

    /**
     * Şirketin rezervasyonları (tenant scope aktif; aktif olanlar önce).
     *
     * @return LengthAwarePaginator<int, Booking>
     */
    public function forCompany(Company $company, int $perPage = 25): LengthAwarePaginator
    {
        $active = "'".implode("','", BookingStatus::blockingValues())."'";

        return Booking::query()->where('company_id', $company->id)
            ->with(['room', 'location', 'booker'])
            ->orderByRaw("case when status in ({$active}) and ends_at > ? then 0 else 1 end", [Carbon::now()])
            ->orderByDesc('starts_at')
            ->paginate($perPage)->withQueryString();
    }

    /**
     * Resepsiyon masası: lokasyonun bir günlük programı (organizasyon bağlamı yok; yalnız booking.view rotasından).
     *
     * @return Collection<int, Booking>
     */
    public function forLocationDay(Location $location, Carbon $day): Collection
    {
        return Booking::withoutTenantScope()
            ->where('location_id', $location->id)
            ->where('starts_at', '>=', $day->copy()->startOfDay())
            ->where('starts_at', '<', $day->copy()->startOfDay()->addDay())
            ->with(['room', 'booker', 'company' => fn ($q) => $q->withoutGlobalScope(TenantScope::class)])
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Takvim (§50): lokasyonun bir aralıktaki rezervasyonları (hafta/gün görünümü), oda bazında.
     *
     * @return Collection<int, Booking>
     */
    public function forLocationRange(Location $location, Carbon $from, Carbon $to): Collection
    {
        return Booking::withoutTenantScope()
            ->where('location_id', $location->id)
            // Takvimde iptal/red/süresi dolan görünmez; tamamlanan ve gelmedi (geçmiş) görünür.
            ->whereNotIn('status', [BookingStatus::REJECTED->value, BookingStatus::CANCELLED->value, BookingStatus::EXPIRED->value])
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->with(['room', 'booker', 'company' => fn ($q) => $q->withoutGlobalScope(TenantScope::class)])
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Personel genel listesi (booking.view global) — sekme + süzgeç.
     *
     * @param  array{tab?: string|null, location_id?: int|null, room_id?: int|null, from?: string|null, q?: string|null}  $filters
     * @return LengthAwarePaginator<int, Booking>
     */
    public function paginateAll(array $filters, int $perPage = 50): LengthAwarePaginator
    {
        $now = Carbon::now();
        $q = trim((string) ($filters['q'] ?? ''));
        $tab = (string) ($filters['tab'] ?? 'all');

        $query = Booking::withoutTenantScope()
            ->when($filters['location_id'] ?? null, fn (Builder $b, $id) => $b->where('location_id', (int) $id))
            ->when($filters['room_id'] ?? null, fn (Builder $b, $id) => $b->where('room_id', (int) $id))
            ->when($filters['from'] ?? null, fn (Builder $b, $d) => $b->where('starts_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($q !== '', fn (Builder $b) => $b->where(fn (Builder $w) => $w->where('reference', 'like', "%{$q}%")->orWhere('customer_name', 'like', "%{$q}%")->orWhere('customer_email', 'like', "%{$q}%")->orWhere('customer_phone', 'like', "%{$q}%")->orWhere('company_name', 'like', "%{$q}%")));

        match ($tab) {
            'pending' => $query->whereIn('status', [BookingStatus::REQUESTED->value, BookingStatus::PENDING_APPROVAL->value]),
            'confirmed' => $query->whereIn('status', [BookingStatus::CONFIRMED->value, BookingStatus::CHECKED_IN->value]),
            'today' => $query->whereIn('status', BookingStatus::blockingValues())->whereBetween('starts_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()]),
            'upcoming' => $query->whereIn('status', BookingStatus::blockingValues())->where('starts_at', '>', $now),
            'completed' => $query->where('status', BookingStatus::COMPLETED->value),
            'cancelled' => $query->whereIn('status', [BookingStatus::CANCELLED->value, BookingStatus::EXPIRED->value, BookingStatus::NO_SHOW->value]),
            'rejected' => $query->where('status', BookingStatus::REJECTED->value),
            default => $query,
        };

        return $query
            ->with(['room', 'location', 'booker', 'company' => fn ($c) => $c->withoutGlobalScope(TenantScope::class)])
            ->orderBy('starts_at', $tab === 'pending' || $tab === 'today' || $tab === 'upcoming' ? 'asc' : 'desc')
            ->paginate($perPage)->withQueryString();
    }

    /**
     * Menü rozeti (faz 38): onay bekleyen rezervasyon alt sorgusu — yalnız booking.view
     * (global) rotasından; PanelBadgeService tek sorguda sayar.
     *
     * @return Builder<Booking>
     */
    public function pendingQuery(): Builder
    {
        return Booking::withoutTenantScope()->whereIn('status', [BookingStatus::REQUESTED->value, BookingStatus::PENDING_APPROVAL->value]);
    }

    /** Sekme sayaçları. @return array<string, int> */
    public function tabCounts(): array
    {
        $now = Carbon::now();
        $base = fn () => Booking::withoutTenantScope();

        return [
            'pending' => $base()->whereIn('status', [BookingStatus::REQUESTED->value, BookingStatus::PENDING_APPROVAL->value])->count(),
            'today' => $base()->whereIn('status', BookingStatus::blockingValues())->whereBetween('starts_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])->count(),
            'upcoming' => $base()->whereIn('status', BookingStatus::blockingValues())->where('starts_at', '>', $now)->count(),
        ];
    }

    /** Personel/resepsiyon için rezervasyonu şirket bağlamı olmadan bulur. */
    public function findAny(int $id): ?Booking
    {
        return Booking::withoutTenantScope()->with(['room', 'location', 'booker', 'approver', 'canceller', 'history.actor', 'company' => fn ($q) => $q->withoutGlobalScope(TenantScope::class)])->find($id);
    }

    /** Vitrin: müşteri kendi talebini uuid ile görür (giriş yok; tahmin edilemez anahtar). */
    public function findByUuid(string $uuid): ?Booking
    {
        return Booking::withoutTenantScope()->with(['room', 'location'])->where('uuid', $uuid)->first();
    }

    /**
     * Resepsiyon masası şirket seçimi: aktif şirketler (tüm organizasyonlar).
     *
     * @return Collection<int, Company>
     */
    public function companiesForDesk(): Collection
    {
        return Company::withoutTenantScope()->whereNotIn('status', self::BLOCKED_COMPANY_STATUSES)->orderBy('legal_name')->get(['id', 'legal_name', 'status']);
    }

    /**
     * Vitrin: rezervasyona açık oda var mı — site sürüm önbelleğinde (ContentCache), oda değişince düşer.
     */
    public function hasBookableRooms(?Website $website): bool
    {
        $compute = fn (): bool => Room::query()->where('is_active', true)->exists();

        return $website === null ? $compute() : (bool) $this->contentCache->remember($website, 'has_rooms', $compute);
    }

    /** Oda değişti: vitrin saat çipleri ve site sürümleri (oda listesi ana sayfada) yenilenir. */
    private function bumpSiteCaches(): void
    {
        Cache::forget(self::SLOTS_CACHE_KEY);

        foreach (Website::query()->get() as $website) {
            $this->contentCache->invalidate($website);
        }
    }

    /**
     * Vitrin saat çipleri: rezervasyona açık odaların slot başlangıçlarının birleşimi (önbellekli).
     *
     * @return array<int, string>
     */
    public function publicSlots(): array
    {
        $cached = Cache::remember(self::SLOTS_CACHE_KEY, 3600, function (): array {
            $slots = [];

            foreach ($this->bookableRooms(true) as $room) {
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

    /**
     * Dashboard (§20): gerçek toplamlar — sahte sayaç yok.
     *
     * @return array{today: int, pending: int, upcoming: int, rooms: int, occupancy_today: float, cancel_rate_30d: float, no_show_30d: int, revenue_30d: int, avg_hours_30d: float, by_location: array<int, array{name: string, count: int}>}
     */
    public function dashboard(): array
    {
        $now = Carbon::now();
        $since = $now->copy()->subDays(30);
        $base = fn () => Booking::withoutTenantScope();
        $rooms = Room::query()->where('is_active', true)->get();
        $locations = Location::query()->pluck('name', 'id');

        // Bugünkü doluluk: aktif dakikalar / açık dakikalar.
        $openMinutes = (int) $rooms->sum(fn (Room $r) => $this->minutes($r->open_until) - $this->minutes($r->open_from));
        $todayRows = $base()->whereIn('status', BookingStatus::blockingValues())->whereBetween('starts_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])->get(['starts_at', 'ends_at']);
        $bookedMinutes = (int) $todayRows->sum(fn (Booking $b) => $b->starts_at->diffInMinutes($b->ends_at));

        $last30 = $base()->where('created_at', '>=', $since)->get(['status', 'total_amount', 'starts_at', 'ends_at', 'location_id']);
        $decided = $last30->filter(fn (Booking $b) => ! $b->isPending());

        return [
            'today' => $todayRows->count(),
            'pending' => $base()->whereIn('status', [BookingStatus::REQUESTED->value, BookingStatus::PENDING_APPROVAL->value])->count(),
            'upcoming' => $base()->whereIn('status', BookingStatus::blockingValues())->where('starts_at', '>', $now)->count(),
            'rooms' => $rooms->count(),
            'occupancy_today' => $openMinutes > 0 ? round($bookedMinutes / $openMinutes * 100, 1) : 0.0,
            'cancel_rate_30d' => $decided->count() > 0 ? round($decided->filter(fn (Booking $b) => $b->status === BookingStatus::CANCELLED)->count() / $decided->count() * 100, 1) : 0.0,
            'no_show_30d' => $last30->filter(fn (Booking $b) => $b->status === BookingStatus::NO_SHOW)->count(),
            'revenue_30d' => (int) $last30->filter(fn (Booking $b) => in_array($b->status, [BookingStatus::CONFIRMED, BookingStatus::CHECKED_IN, BookingStatus::COMPLETED], true))->sum('total_amount'),
            'avg_hours_30d' => $last30->count() > 0 ? round((float) $last30->avg(fn (Booking $b) => $b->hours()), 1) : 0.0,
            'by_location' => $last30->groupBy('location_id')->map(fn (Collection $g, $id) => ['name' => (string) ($locations[$id] ?? '#'.$id), 'count' => $g->count()])->values()->all(),
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
