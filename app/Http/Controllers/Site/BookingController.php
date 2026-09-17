<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePublicBookingRequest;
use App\Services\BookingService;
use App\Services\CurrentWebsite;
use App\Services\GeoService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Vitrin rezervasyon akışı (master prompt §57): lokasyon → gerçek odalar → gün →
 * uygunluk (sunucuda) → saat → form → talep (PENDING_APPROVAL ya da politika gereği
 * CONFIRMED) → müşteri uuid ile durumunu görür. Frontend durum belirlemez; tüm
 * kurallar BookingService'te ikinci kez doğrulanır. Sayfa önbelleğe alınmaz
 * (uygunluk canlı veridir).
 */
class BookingController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly GeoService $geo,
        private readonly CurrentWebsite $website,
    ) {}

    public function index(Request $request): View
    {
        abort_if($this->website->isTenantSite(), 404);

        $locations = $this->geo->publishedLocations()->filter(fn ($l) => $l->is_active)->values();
        $locationId = (int) ($request->query('lokasyon') ?? old('location_id') ?? ($locations->first()->id ?? 0));
        $location = $locations->firstWhere('id', $locationId) ?? $locations->first();
        $day = $this->day((string) ($request->query('gun') ?? old('date') ?? Carbon::today()->toDateString()));
        $rooms = $location ? $this->bookings->bookableRooms(true, $location->id) : collect();
        $policy = $this->bookings->policy($location?->id);

        return view('site.booking', [
            'locations' => $locations,
            'location' => $location,
            'day' => $day,
            'rooms' => $rooms,
            'grid' => $rooms->mapWithKeys(fn ($room) => [$room->id => $this->bookings->availability($room, $day)]),
            'policy' => $policy,
            'badge' => $this->bookings->confirmationBadge($location?->id),
            'selectedRoom' => (int) old('room_id', (string) ($request->query('oda') ?? '')),
        ]);
    }

    public function store(StorePublicBookingRequest $request): RedirectResponse
    {
        abort_if($this->website->isTenantSite(), 404);

        $v = $request->validated();
        $room = $this->bookings->findRoom((int) $v['room_id']);

        if ($room === null || ! $room->is_active || ! $room->location->is_published) {
            return back()->withErrors(['room_id' => 'Oda bulunamadı.'])->withInput();
        }

        try {
            $booking = $this->bookings->book(null, null, $room, [
                'date' => $v['date'], 'start' => $v['start'], 'hours' => (float) $v['hours'],
                'participants' => (int) ($v['participants'] ?? 1), 'note' => $v['note'] ?? null,
                'customer_name' => $v['name'], 'customer_email' => $v['email'], 'customer_phone' => $v['phone'], 'company_name' => $v['company_name'] ?? null,
                'source' => 'site', 'consent_ip' => $request->ip(),
            ]);
        } catch (DomainException $e) {
            return back()->withErrors(['start' => $e->getMessage()])->withInput();
        }

        return redirect()->route('site.booking.show', $booking->uuid)->with('booking_created', true);
    }

    public function show(string $uuid): View
    {
        abort_if($this->website->isTenantSite(), 404);

        $booking = $this->bookings->findByUuid($uuid);

        abort_if($booking === null, 404);

        return view('site.booking-status', ['b' => $booking, 'badge' => $this->bookings->confirmationBadge($booking->location_id)]);
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
