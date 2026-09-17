<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Space;
use App\Services\LocationMediaService;
use App\Services\SpaceService;
use App\Support\Money;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Lokasyonun masa & ofis envanteri (audit P0-2) — lokasyon künyesinin parçası, geo.edit.
 * {space} lokasyona ait olmalı; aksi 404. Tahsis işlemleri SpaceController'da (space.manage).
 */
class LocationSpaceController extends Controller
{
    public function __construct(private readonly SpaceService $spaces, private readonly LocationMediaService $media) {}

    public function index(Location $location): View
    {
        return view('panel.geo.spaces', [
            'location' => $location,
            'spaces' => $this->spaces->forLocation($location),
            'kinds' => Space::KINDS,
            'occupancy' => $this->spaces->occupancy($location),
            'gallery' => $this->media->links($location),
        ]);
    }

    public function store(Request $request, Location $location): RedirectResponse
    {
        try {
            $space = $this->spaces->create($request->user(), $location, $this->validated($request));
        } catch (DomainException $e) {
            return back()->withErrors(['name' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.geo.spaces.index', $location)->with('status', $space->name.' eklendi.');
    }

    public function update(Request $request, Location $location, int $space): RedirectResponse
    {
        try {
            $this->spaces->update($request->user(), $this->spaceOf($location, $space), $this->validated($request));
        } catch (DomainException $e) {
            return back()->withErrors(['name' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.geo.spaces.index', $location)->with('status', 'Alan güncellendi.');
    }

    public function destroy(Request $request, Location $location, int $space): RedirectResponse
    {
        try {
            $this->spaces->delete($request->user(), $this->spaceOf($location, $space));
        } catch (DomainException $e) {
            return back()->withErrors(['space' => $e->getMessage()]);
        }

        return redirect()->route('panel.geo.spaces.index', $location)->with('status', 'Alan silindi.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $v = $request->validate([
            'kind' => ['required', Rule::in(array_keys(Space::KINDS))],
            'name' => ['required', 'string', 'max:60'],
            'floor' => ['nullable', 'string', 'max:30'],
            'zone' => ['nullable', 'string', 'max:60'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:500'],
            'monthly_price' => ['nullable', Money::RULE],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'notes' => ['nullable', 'string', 'max:300'],
            'amenities' => ['nullable', 'string', 'max:1000'],
            'cover_media_id' => ['nullable', 'integer', 'min:1'],
            'maintenance_until' => ['nullable', 'date_format:Y-m-d'],
            'maintenance_note' => ['nullable', 'string', 'max:200', 'required_with:maintenance_until'],
        ]);

        return array_replace($v, ['monthly_price' => Money::parse((string) ($v['monthly_price'] ?? 0)), 'is_active' => $request->boolean('is_active')]); // doğrulanmış ham değerin üstüne yaz
    }

    private function spaceOf(Location $location, int $id): Space
    {
        $space = $this->spaces->find($id);

        if ($space === null || (int) $space->location_id !== (int) $location->id) {
            abort(404);
        }

        return $space;
    }
}
