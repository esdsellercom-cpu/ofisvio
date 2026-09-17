<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Company;
use App\Services\BookingService;
use App\Services\TenantContext;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Müşteri rezervasyonları (booking engine v2) — şirket kapsamı. Yetki route'ta:
 * booking.view / booking.create / booking.cancel ,company. {booking} scopeBindings
 * ile {company}->bookings() üzerinden çözülür. Onay politikası (ayar) durumu belirler;
 * müşteri durum gönderemez.
 */
class BookingController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request, Company $company): View
    {
        $this->context->toArray($request->user(), $company->id);

        return view('panel.bookings.index', [
            'company' => $company,
            'bookings' => $this->bookings->forCompany($company),
            'policy' => $this->bookings->policy(null),
        ]);
    }

    public function create(Request $request, Company $company): View
    {
        $this->context->toArray($request->user(), $company->id);
        $rooms = $this->bookings->bookableRooms();
        $first = $rooms->first();
        $roomId = (int) ($request->query('oda') ?? old('room_id') ?? ($first !== null ? $first->id : 0));
        $room = $rooms->firstWhere('id', $roomId) ?? $first;
        $day = $this->day((string) ($request->query('gun') ?? old('date') ?? Carbon::today()->toDateString()));
        $policy = $this->bookings->policy($room?->location_id);

        return view('panel.bookings.create', [
            'company' => $company,
            'rooms' => $rooms,
            'room' => $room,
            'day' => $day,
            'slots' => $room ? $this->bookings->availability($room, $day) : [],
            'horizonDays' => $policy['max_advance_days'],
            'policy' => $policy,
            'badge' => $this->bookings->confirmationBadge($room?->location_id),
            'formAction' => route('panel.companies.bookings.store', $company),
            'indexUrl' => route('panel.companies.bookings.index', $company),
        ]);
    }

    public function store(StoreBookingRequest $request, Company $company): RedirectResponse
    {
        $this->context->toArray($request->user(), $company->id);
        $v = $request->validated();
        $room = $this->bookings->findRoom((int) $v['room_id']);

        if ($room === null) {
            return back()->withErrors(['room_id' => 'Oda bulunamadı.'])->withInput();
        }

        try {
            $booking = $this->bookings->book($request->user(), $company, $room, ['date' => $v['date'], 'start' => $v['start'], 'hours' => (float) $v['hours'], 'note' => $v['note'] ?? null, 'participants' => (int) ($v['participants'] ?? 1), 'source' => 'panel']);
        } catch (DomainException $e) {
            return back()->withErrors(['start' => $e->getMessage()])->withInput();
        }

        $message = $booking->reference.' · '.$room->name.' · '.$booking->starts_at->format('d.m.Y H:i').'–'.$booking->ends_at->format('H:i');
        $message .= $booking->isPending() ? ' — talep alındı, yönetici onayı bekliyor.' : ' rezerve edildi.';

        return redirect()->route('panel.companies.bookings.index', $company)->with('status', $message);
    }

    public function cancel(Request $request, Company $company, Booking $booking): RedirectResponse
    {
        $this->context->toArray($request->user(), $company->id);
        $reason = (string) ($request->validate(['reason' => ['nullable', 'string', 'max:200']])['reason'] ?? '');

        try {
            $this->bookings->cancel($request->user(), $booking, $reason);
        } catch (DomainException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        return redirect()->route('panel.companies.bookings.index', $company)->with('status', 'Rezervasyon iptal edildi.');
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
