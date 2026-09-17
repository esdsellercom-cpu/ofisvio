<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\RequestJitAccessRequest;
use App\Http\Requests\StoreBookingRequest;
use App\Models\Location;
use App\Services\AuthorizationService;
use App\Services\BookingService;
use App\Services\GeoService;
use App\Services\JitAccessService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Personel tarafı (booking v1): genel liste (booking.view global) ve lokasyon
 * masası (booking.view,location — resepsiyon kendi şubesi, operations_admin hepsi).
 * Masadan rezervasyon: booking.create,location (resepsiyon) ya da
 * booking.admin_override (JIT; kural dışı saat/ufuk açar, çakışmayı açmaz).
 * İptal masadan yalnız admin_override ile (müşteri kendi iptalini panelinden yapar).
 *
 * JIT kaynağı: booking_location / lokasyon id.
 */
class BookingDeskController extends Controller
{
    public const RESOURCE = 'booking_location';

    public function __construct(
        private readonly BookingService $bookings,
        private readonly GeoService $geo,
        private readonly JitAccessService $jit,
        private readonly AuthorizationService $authorization,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'lokasyon' => ['nullable', 'integer'],
            'durum' => ['nullable', Rule::in(['confirmed', 'cancelled'])],
            'baslangic' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return view('panel.bookings.desk.index', [
            'rows' => $this->bookings->paginateAll(['location_id' => $filters['lokasyon'] ?? null, 'status' => $filters['durum'] ?? null, 'from' => $filters['baslangic'] ?? null]),
            'locations' => $this->geo->allLocations(),
            'counts' => $this->bookings->counts(),
            'filters' => $filters,
        ]);
    }

    public function location(Request $request, Location $location): View
    {
        $day = $this->day((string) $request->query('gun', Carbon::today()->toDateString()));
        $rooms = $this->bookings->rooms($location);
        $user = $request->user();
        $override = $this->jit->allows($user, 'booking.admin_override', ['location_id' => $location->id], self::RESOURCE, $location->id);

        return view('panel.bookings.desk.location', [
            'location' => $location,
            'day' => $day,
            'rows' => $this->bookings->forLocationDay($location, $day),
            'rooms' => $rooms,
            'grid' => $rooms->where('is_active', true)->mapWithKeys(fn ($room) => [$room->id => $this->bookings->availability($room, $day)]),
            'companies' => $this->bookings->companiesForDesk(),
            'canCreate' => $this->jit->allows($user, 'booking.create', ['location_id' => $location->id]) || $override,
            'hasOverride' => $override,
            'canRequestOverride' => ! $override && $this->authorization->can($user, 'booking.admin_override', ['location_id' => $location->id]),
            'defaultTtl' => JitAccessService::DEFAULT_TTL_MINUTES,
            'maxTtl' => JitAccessService::MAX_TTL_MINUTES,
        ]);
    }

    public function store(StoreBookingRequest $request, Location $location): RedirectResponse
    {
        $v = $request->validated();
        $user = $request->user();
        $room = $this->bookings->findRoom((int) $v['room_id']);
        $company = $this->bookings->companiesForDesk()->firstWhere('id', (int) ($v['company_id'] ?? 0));

        if ($room === null || (int) $room->location_id !== (int) $location->id) {
            return back()->withErrors(['room_id' => 'Oda bu lokasyonda değil.'])->withInput();
        }

        if ($company === null) {
            return back()->withErrors(['company_id' => 'Şirket seçin.'])->withInput();
        }

        // Override yalnız açık JIT grant'i varsa ve kutu işaretliyse uygulanır.
        $override = (bool) ($v['override'] ?? false)
            && $this->jit->allows($user, 'booking.admin_override', ['location_id' => $location->id], self::RESOURCE, $location->id);

        if (! $override && ! $this->jit->allows($user, 'booking.create', ['location_id' => $location->id])) {
            abort(403, 'Bu lokasyonda rezervasyon açma yetkiniz yok (booking.create ya da açık JIT).');
        }

        try {
            $booking = $this->bookings->book($user, $company, $room, ['date' => $v['date'], 'start' => $v['start'], 'hours' => (float) $v['hours'], 'note' => $v['note'] ?? null], $override);
        } catch (DomainException $e) {
            return back()->withErrors(['start' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.bookings.location', [$location, 'gun' => $booking->starts_at->toDateString()])
            ->with('status', $company->legal_name.' · '.$room->name.' · '.$booking->starts_at->format('d.m.Y H:i').' rezerve edildi'.($override ? ' (kural dışı, JIT)' : '').'.');
    }

    /** booking.admin_override (JIT): masadan iptal, süre kuralı yok. */
    public function cancel(Request $request, Location $location, int $booking): RedirectResponse
    {
        $record = $this->bookings->findAny($booking);

        if ($record === null || (int) $record->location_id !== (int) $location->id) {
            abort(404);
        }

        $reason = (string) ($request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']])['reason']);

        try {
            $this->bookings->cancel($request->user(), $record, $reason, true);
        } catch (DomainException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        return redirect()->route('panel.bookings.location', [$location, 'gun' => $record->starts_at->toDateString()])->with('status', 'Rezervasyon iptal edildi (JIT).');
    }

    public function requestJit(RequestJitAccessRequest $request, Location $location): RedirectResponse
    {
        $v = $request->validated();
        $grantId = $this->jit->grant($request->user(), 'booking.admin_override', ['location_id' => $location->id], self::RESOURCE, $location->id, $v['reason'], null, (int) $v['ttl_minutes']);

        if ($grantId === null) {
            return back()->withErrors(['reason' => 'JIT erişimi açılamadı: rolünüz booking.admin_override taşımıyor.']);
        }

        return redirect()->route('panel.bookings.location', $location)->with('status', $location->name.' için '.$v['ttl_minutes'].' dakikalık kural dışı erişim açıldı.');
    }

    private function day(string $value): Carbon
    {
        try {
            $day = Carbon::createFromFormat('Y-m-d', $value) ?: Carbon::today();
        } catch (\Throwable) {
            $day = Carbon::today();
        }

        return $day->startOfDay();
    }
}
