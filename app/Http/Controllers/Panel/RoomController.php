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

/**
 * Oda yönetimi (booking v1) — lokasyon künyesinin parçası, geo.edit.
 * {room} lokasyona ait olmalı; aksi 404 (bkz. roomOf).
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
            $room = $this->bookings->createRoom($location, $request->roomData());
        } catch (DomainException $e) {
            return back()->withErrors(['open_until' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.geo.rooms.index', $location)->with('status', $room->name.' eklendi.');
    }

    public function update(StoreRoomRequest $request, Location $location, int $room): RedirectResponse
    {
        try {
            $this->bookings->updateRoom($this->roomOf($location, $room), $request->roomData());
        } catch (DomainException $e) {
            return back()->withErrors(['open_until' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.geo.rooms.index', $location)->with('status', 'Oda güncellendi.');
    }

    public function destroy(Location $location, int $room): RedirectResponse
    {
        try {
            $this->bookings->deleteRoom($this->roomOf($location, $room));
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
