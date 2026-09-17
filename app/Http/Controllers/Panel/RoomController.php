<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoomRequest;
use App\Models\Location;
use App\Models\Room;
use App\Services\BookingService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Oda yönetimi (booking engine) — lokasyon künyesinin parçası, geo.edit.
 * {room} lokasyona ait olmalı; aksi 404 (bkz. roomOf). Her değişiklik audit'e düşer.
 */
class RoomController extends Controller
{
    public function __construct(private readonly BookingService $bookings) {}

    public function index(Location $location): View
    {
        return view('panel.geo.rooms', [
            'location' => $location,
            'rooms' => $this->bookings->rooms($location),
            'kinds' => Room::KINDS,
        ]);
    }

    public function store(StoreRoomRequest $request, Location $location): RedirectResponse
    {
        try {
            $room = $this->bookings->createRoom($request->user(), $location, $request->roomData());
        } catch (DomainException $e) {
            return back()->withErrors(['open_until' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.geo.rooms.index', $location)->with('status', $room->name.' eklendi.');
    }

    public function update(StoreRoomRequest $request, Location $location, int $room): RedirectResponse
    {
        try {
            $this->bookings->updateRoom($request->user(), $this->roomOf($location, $room), $request->roomData());
        } catch (DomainException $e) {
            return back()->withErrors(['open_until' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.geo.rooms.index', $location)->with('status', 'Oda güncellendi.');
    }

    public function destroy(Request $request, Location $location, int $room): RedirectResponse
    {
        try {
            $this->bookings->deleteRoom($request->user(), $this->roomOf($location, $room));
        } catch (DomainException $e) {
            return back()->withErrors(['room' => $e->getMessage()]);
        }

        return redirect()->route('panel.geo.rooms.index', $location)->with('status', 'Oda silindi.');
    }

    private function roomOf(Location $location, int $id): Room
    {
        $room = $this->bookings->findRoom($id);

        if ($room === null || (int) $room->location_id !== (int) $location->id) {
            abort(404);
        }

        return $room;
    }
}
