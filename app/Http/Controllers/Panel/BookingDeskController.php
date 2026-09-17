<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\RequestJitAccessRequest;
use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Location;
use App\Services\AuditService;
use App\Services\AuthorizationService;
use App\Services\BookingService;
use App\Services\GeoService;
use App\Services\JitAccessService;
use App\Services\NotificationService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Personel tarafı (booking engine v2, master prompt §9–10):
 *   index      booking.view (global)                     — sekmeli genel liste + dashboard sayaçları
 *   location   booking.view|admin_override ,location     — lokasyon masası (resepsiyon kendi şubesi)
 *   show       booking.view|admin_override ,location     — detay: geçmiş, bildirimler, denetim, eylemler
 *   store      booking.create|admin_override ,location   — masadan rezervasyon (JIT ile kural dışı)
 *   approve/reject         booking.approve ,location
 *   checkin/complete/noshow/note/reschedule  booking.manage ,location
 *   cancel     booking.admin_override (JIT, kaynak booking_location) — masadan iptal, süre kuralı yok
 *
 * Rezervasyon her zaman lokasyon yoluyla adreslenir; kayıt o lokasyona ait değilse 404.
 */
class BookingDeskController extends Controller
{
    public const RESOURCE = 'booking_location';

    public function __construct(
        private readonly BookingService $bookings,
        private readonly GeoService $geo,
        private readonly JitAccessService $jit,
        private readonly AuthorizationService $authorization,
        private readonly NotificationService $notifications,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'sekme' => ['nullable', Rule::in(array_keys(BookingService::TABS))],
            'lokasyon' => ['nullable', 'integer'],
            'baslangic' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:80'],
        ]);

        return view('panel.bookings.desk.index', [
            'rows' => $this->bookings->paginateAll(['tab' => $filters['sekme'] ?? 'all', 'location_id' => $filters['lokasyon'] ?? null, 'from' => $filters['baslangic'] ?? null, 'q' => $filters['q'] ?? null]),
            'locations' => $this->geo->allLocations(),
            'stats' => $this->bookings->dashboard(),
            'tabs' => BookingService::TABS,
            'tabCounts' => $this->bookings->tabCounts(),
            'tab' => $filters['sekme'] ?? 'all',
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
            'policy' => $this->bookings->policy($location->id),
            'defaultTtl' => JitAccessService::DEFAULT_TTL_MINUTES,
            'maxTtl' => JitAccessService::MAX_TTL_MINUTES,
        ]);
    }

    public function show(Request $request, Location $location, int $booking): View
    {
        $record = $this->bookingOf($location, $booking);
        $user = $request->user();
        $ctx = ['location_id' => $location->id];

        return view('panel.bookings.desk.show', [
            'location' => $location,
            'b' => $record,
            'rooms' => $this->bookings->rooms($location, true),
            'notificationLogs' => $this->notifications->logsFor('booking', $record->id),
            'auditTrail' => $this->audit->trail('booking', $record->id),
            'canApprove' => $this->authorization->can($user, 'booking.approve', $ctx),
            'canManage' => $this->authorization->can($user, 'booking.manage', $ctx),
            'hasOverride' => $this->jit->allows($user, 'booking.admin_override', $ctx, self::RESOURCE, $location->id),
            'sources' => Booking::SOURCES,
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
            $booking = $this->bookings->book($user, $company, $room, ['date' => $v['date'], 'start' => $v['start'], 'hours' => (float) $v['hours'], 'note' => $v['note'] ?? null, 'participants' => (int) ($v['participants'] ?? 1), 'source' => 'desk'], $override);
        } catch (DomainException $e) {
            return back()->withErrors(['start' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.bookings.location', [$location, 'gun' => $booking->starts_at->toDateString()])
            ->with('status', $booking->reference.' · '.$company->legal_name.' · '.$room->name.' · '.$booking->starts_at->format('d.m.Y H:i').' — '.$booking->statusLabel().($override ? ' (kural dışı, JIT)' : '').'.');
    }

    public function approve(Request $request, Location $location, int $booking): RedirectResponse
    {
        $note = (string) ($request->validate(['note' => ['nullable', 'string', 'max:300']])['note'] ?? '');

        return $this->act($location, $booking, fn (Booking $b) => $this->bookings->approve($request->user(), $b, $note !== '' ? $note : null), 'Rezervasyon onaylandı; müşteriye bildirim kuyruğa alındı.');
    }

    public function reject(Request $request, Location $location, int $booking): RedirectResponse
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']])['reason'];

        return $this->act($location, $booking, fn (Booking $b) => $this->bookings->reject($request->user(), $b, $reason), 'Talep reddedildi.');
    }

    public function checkIn(Request $request, Location $location, int $booking): RedirectResponse
    {
        return $this->act($location, $booking, fn (Booking $b) => $this->bookings->checkIn($request->user(), $b), 'Giriş kaydedildi.');
    }

    public function complete(Request $request, Location $location, int $booking): RedirectResponse
    {
        return $this->act($location, $booking, fn (Booking $b) => $this->bookings->complete($request->user(), $b), 'Rezervasyon tamamlandı.');
    }

    public function noShow(Request $request, Location $location, int $booking): RedirectResponse
    {
        $note = (string) ($request->validate(['note' => ['nullable', 'string', 'max:200']])['note'] ?? '');

        return $this->act($location, $booking, fn (Booking $b) => $this->bookings->noShow($request->user(), $b, $note !== '' ? $note : null), '"Gelmedi" işaretlendi.');
    }

    public function note(Request $request, Location $location, int $booking): RedirectResponse
    {
        $note = (string) ($request->validate(['internal_note' => ['nullable', 'string', 'max:500']])['internal_note'] ?? '');

        return $this->act($location, $booking, fn (Booking $b) => $this->bookings->setInternalNote($request->user(), $b, $note), 'İç not kaydedildi.');
    }

    public function reschedule(Request $request, Location $location, int $booking): RedirectResponse
    {
        $v = $request->validate(['room_id' => ['required', 'integer'], 'date' => ['required', 'date_format:Y-m-d'], 'start' => ['required', 'date_format:H:i'], 'hours' => ['required', 'numeric', 'min:0.5', 'max:24']]);
        $room = $this->bookings->findRoom((int) $v['room_id']);

        if ($room === null || (int) $room->location_id !== (int) $location->id) {
            return back()->withErrors(['room_id' => 'Oda bu lokasyonda değil.']);
        }

        return $this->act($location, $booking, fn (Booking $b) => $this->bookings->reschedule($request->user(), $b, Carbon::parse($v['date'].' '.$v['start']), (int) round(((float) $v['hours']) * 60), $room), 'Rezervasyon yeniden planlandı.', 'start');
    }

    /** booking.admin_override (JIT): masadan iptal, süre kuralı yok. */
    public function cancel(Request $request, Location $location, int $booking): RedirectResponse
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']])['reason'];

        return $this->act($location, $booking, fn (Booking $b) => $this->bookings->cancel($request->user(), $b, $reason, true), 'Rezervasyon iptal edildi (JIT).');
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

    /** @param  callable(Booking): Booking  $action */
    private function act(Location $location, int $bookingId, callable $action, string $message, string $errorKey = 'booking'): RedirectResponse
    {
        $record = $this->bookingOf($location, $bookingId);

        try {
            $action($record);
        } catch (DomainException $e) {
            return back()->withErrors([$errorKey => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.bookings.show', [$location, $record->id])->with('status', $message);
    }

    private function bookingOf(Location $location, int $id): Booking
    {
        $record = $this->bookings->findAny($id);

        if ($record === null || (int) $record->location_id !== (int) $location->id) {
            abort(404);
        }

        return $record;
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
