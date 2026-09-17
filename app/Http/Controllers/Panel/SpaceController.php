<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Services\BookingService;
use Illuminate\Contracts\View\View;

/**
 * Masalar, ofisler & odalar (faz 39, artifact §3): tüm lokasyonların alanları tek
 * ekranda — tür, kapasite, saat, ücret, durum. Düzenleme lokasyonun oda ekranında
 * (geo.edit); burası genel bakış (geo.view | booking.view).
 */
class SpaceController extends Controller
{
    public function __construct(private readonly BookingService $bookings) {}

    public function index(): View
    {
        $rooms = $this->bookings->allRooms();

        return view('panel.spaces.index', [
            'byLocation' => $rooms->groupBy(fn (Room $r) => $r->location->name),
            'kinds' => Room::KINDS,
            'counts' => [
                'total' => $rooms->count(),
                'active' => $rooms->where('is_active', true)->count(),
                'capacity' => (int) $rooms->where('is_active', true)->sum('capacity'),
                'locations' => $rooms->pluck('location_id')->unique()->count(),
            ],
        ]);
    }
}
