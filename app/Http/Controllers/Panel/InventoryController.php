<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoomRequest;
use App\Models\Asset;
use App\Models\Location;
use App\Models\Space;
use App\Models\SpaceAssignment;
use App\Services\AssetService;
use App\Services\AuthorizationService;
use App\Services\BookingService;
use App\Services\GeoService;
use App\Services\MembershipService;
use App\Services\SpaceService;
use App\Support\Money;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Envanter ekranı yazma işlemleri (faz 46): "Masalar, ofisler & odalar" tek ekrandan envanter (alan/oda)
 * ekle-düzenle, hızlı tahsis, tahsis düzenle/sonlandır, demirbaş ekle-düzenle-sil. Okuma SpaceController::index.
 *
 * Yetki route'ta: alan/tahsis/demirbaş space.manage (anylocation — lokasyon kapsamlı personel yalnız kendi
 * lokasyonu; burada hedef lokasyon görünür değilse 404), oda geo.edit (global). Her yol modal'a döner.
 */
class InventoryController extends Controller
{
    public function __construct(
        private readonly SpaceService $spaces,
        private readonly BookingService $bookings,
        private readonly AssetService $assets,
        private readonly GeoService $geo,
        private readonly MembershipService $members,
        private readonly AuthorizationService $authorization,
    ) {}

    // ---- Envanter: alan ---------------------------------------------------------

    public function storeSpace(Request $request): RedirectResponse
    {
        $data = $this->spaceInput($request);
        $location = $this->locationFor($request, (int) $data['location_id']);

        try {
            $space = $this->spaces->create($request->user(), $location, $data);
        } catch (DomainException $e) {
            return $this->fail($request, 'inventory', 'name', $e->getMessage());
        }

        return $this->done($request, $space->name.' eklendi.');
    }

    public function updateSpace(Request $request, int $space): RedirectResponse
    {
        $record = $this->spaces->find($space) ?? abort(404);
        $this->assertVisible($request, (int) $record->location_id);
        $data = $this->spaceInput($request, $record);

        if ((int) $data['location_id'] !== (int) $record->location_id) {
            // Lokasyon değişimi: yeni lokasyon da görünür olmalı; aktif tahsisi olan alan taşınamaz.
            $this->locationFor($request, (int) $data['location_id']);

            if ($record->occupied() > 0) {
                return $this->fail($request, 'inventory', 'location_id', 'Aktif tahsisi olan alan başka lokasyona taşınamaz.');
            }

            $record->location_id = (int) $data['location_id'];
            $record->unsetRelation('location'); // kapak/ad tekilliği yeni lokasyona göre denetlenir
        }

        try {
            $this->spaces->update($request->user(), $record, $data);
        } catch (DomainException $e) {
            return $this->fail($request, 'inventory', 'name', $e->getMessage());
        }

        return $this->done($request, $record->name.' güncellendi.');
    }

    // ---- Envanter: oda (geo.edit) -------------------------------------------------

    public function storeRoom(StoreRoomRequest $request): RedirectResponse
    {
        $locationId = (int) $request->validate(['location_id' => ['required', 'integer']])['location_id'];
        $location = $this->geo->allLocations()->firstWhere('id', $locationId) ?? abort(404);

        try {
            $room = $this->bookings->createRoom($request->user(), $location, $request->roomData());
        } catch (DomainException $e) {
            return $this->fail($request, 'inventory', 'open_until', $e->getMessage());
        }

        return $this->done($request, $room->name.' eklendi.');
    }

    public function updateRoom(StoreRoomRequest $request, int $room): RedirectResponse
    {
        $record = $this->bookings->findRoom($room) ?? abort(404);

        try {
            $this->bookings->updateRoom($request->user(), $record, $request->roomData());
        } catch (DomainException $e) {
            return $this->fail($request, 'inventory', 'open_until', $e->getMessage());
        }

        return $this->done($request, $record->name.' güncellendi.');
    }

    // ---- Tahsis ------------------------------------------------------------------

    public function quickAssign(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'space_id' => ['required', 'integer'],
            'company_id' => ['required', 'integer'],
            'subscription_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:300'],
            'asset_ids' => ['nullable', 'array', 'max:50'],
            'asset_ids.*' => ['integer'],
        ]);
        $space = $this->spaces->find((int) $data['space_id']) ?? abort(404);
        $this->assertVisible($request, (int) $space->location_id);
        $company = $this->bookings->companiesForDesk()->firstWhere('id', (int) $data['company_id']);

        if ($company === null) {
            return $this->fail($request, 'assign', 'company_id', 'Şirket bulunamadı ya da tahsis alamaz durumda.');
        }

        if (! empty($data['user_id']) && ! $this->members->membersOf($company)->contains(fn ($m) => $m->isActive() && (int) $m->user_id === (int) $data['user_id'])) {
            return $this->fail($request, 'assign', 'user_id', 'Seçilen kişi bu şirketin aktif üyesi değil.');
        }

        try {
            $this->spaces->assign($request->user(), $space, $company, $data);
        } catch (DomainException $e) {
            return $this->fail($request, 'assign', 'company_id', $e->getMessage());
        }

        return $this->done($request, $space->name.' → '.$company->legal_name.' tahsis edildi.', 'tahsisler');
    }

    public function updateAssignment(Request $request, int $assignment): RedirectResponse
    {
        $record = $this->assignmentFor($request, $assignment);
        $data = $request->validate([
            'ends_on' => ['nullable', 'date'],
            'user_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:300'],
            'asset_ids' => ['nullable', 'array', 'max:50'],
            'asset_ids.*' => ['integer'],
        ]);

        if (! empty($data['user_id']) && ! $this->members->membersOf($record->company)->contains(fn ($m) => $m->isActive() && (int) $m->user_id === (int) $data['user_id'])) {
            return $this->fail($request, 'assignment', 'user_id', 'Seçilen kişi bu şirketin aktif üyesi değil.');
        }

        try {
            $this->spaces->updateAssignment($request->user(), $record, $data + ['asset_ids' => $data['asset_ids'] ?? []]);
        } catch (DomainException $e) {
            return $this->fail($request, 'assignment', 'ends_on', $e->getMessage());
        }

        return $this->done($request, 'Tahsis güncellendi.', 'tahsisler');
    }

    public function endAssignment(Request $request, int $assignment): RedirectResponse
    {
        $record = $this->assignmentFor($request, $assignment);

        try {
            $this->spaces->end($request->user(), $record);
        } catch (DomainException $e) {
            return back()->withErrors(['assignment' => $e->getMessage()]);
        }

        return $this->done($request, 'Tahsis sonlandırıldı; demirbaşlar serbest bırakıldı.', 'tahsisler');
    }

    // ---- Demirbaş ----------------------------------------------------------------

    public function storeAsset(Request $request): RedirectResponse
    {
        $data = $this->assetInput($request);
        $location = $this->locationFor($request, (int) $data['location_id']);

        try {
            $asset = $this->assets->create($request->user(), $location, $data);
        } catch (DomainException $e) {
            return $this->fail($request, 'asset', 'name', $e->getMessage());
        }

        return $this->done($request, $asset->name.' demirbaş olarak eklendi.', 'demirbas');
    }

    public function updateAsset(Request $request, int $asset): RedirectResponse
    {
        $record = $this->assets->find($asset) ?? abort(404);
        $this->assertVisible($request, (int) $record->location_id);
        $data = $this->assetInput($request);

        if ((int) $data['location_id'] !== (int) $record->location_id) {
            $this->assertVisible($request, (int) $data['location_id']);

            if ($record->status === 'assigned') {
                return $this->fail($request, 'asset', 'location_id', 'Tahsisli demirbaş başka lokasyona taşınamaz.');
            }

            $record->location_id = (int) $data['location_id'];
            $record->unsetRelation('location');
        }

        try {
            $this->assets->update($request->user(), $record, $data);
        } catch (DomainException $e) {
            return $this->fail($request, 'asset', 'name', $e->getMessage());
        }

        return $this->done($request, $record->name.' güncellendi.', 'demirbas');
    }

    public function destroyAsset(Request $request, int $asset): RedirectResponse
    {
        $record = $this->assets->find($asset) ?? abort(404);
        $this->assertVisible($request, (int) $record->location_id);

        try {
            $this->assets->delete($request->user(), $record);
        } catch (DomainException $e) {
            return back()->withErrors(['asset' => $e->getMessage()]);
        }

        return $this->done($request, $record->name.' silindi.', 'demirbas');
    }

    // ---- Yardımcılar -------------------------------------------------------------

    /** @return array<string, mixed> */
    private function spaceInput(Request $request, ?Space $existing = null): array
    {
        $v = $request->validate([
            'location_id' => ['required', 'integer'],
            'kind' => ['required', Rule::in(array_keys(Space::KINDS))],
            'name' => ['required', 'string', 'max:60'],
            'code' => ['nullable', 'string', 'max:40'],
            'floor' => ['nullable', 'string', 'max:30'],
            'zone' => ['nullable', 'string', 'max:60'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:500'],
            'monthly_price' => ['nullable', Money::RULE],
            'status' => ['required', Rule::in(['active', 'maintenance', 'inactive'])],
            'maintenance_until' => ['nullable', 'date_format:Y-m-d', 'required_if:status,maintenance'],
            'maintenance_note' => ['nullable', 'string', 'max:200'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'notes' => ['nullable', 'string', 'max:300'],
            'amenities' => ['nullable', 'string', 'max:1000'],
            'cover_media_id' => ['nullable', 'integer', 'min:1'],
        ], ['maintenance_until.required_if' => 'Bakım durumu için bakım bitiş tarihi gerekir.']);

        // Durum seçimi → is_active + bakım alanları (bakım değilse bakım temizlenir).
        return array_replace($v, [
            'monthly_price' => Money::parse((string) ($v['monthly_price'] ?? 0)),
            'is_active' => $v['status'] !== 'inactive',
            'maintenance_until' => $v['status'] === 'maintenance' ? $v['maintenance_until'] : null,
            'maintenance_note' => $v['status'] === 'maintenance' ? ($v['maintenance_note'] ?? null) : null,
            'sort_order' => $v['sort_order'] ?? ($existing !== null ? $existing->sort_order : 0),
        ]);
    }

    /** @return array<string, mixed> */
    private function assetInput(Request $request): array
    {
        return $request->validate([
            'location_id' => ['required', 'integer'],
            'space_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'code' => ['nullable', 'string', 'max:40'],
            'category' => ['required', Rule::in(array_keys(Asset::CATEGORIES))],
            'serial' => ['nullable', 'string', 'max:80'],
            'status' => ['required', Rule::in(['available', 'maintenance', 'retired'])],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);
    }

    /** Lokasyon görünür mü (lokasyon kapsamlı space.manage): değilse 404 — varlık sızdırılmaz. */
    private function locationFor(Request $request, int $locationId): Location
    {
        $this->assertVisible($request, $locationId);

        return $this->geo->allLocations()->firstWhere('id', $locationId) ?? abort(404);
    }

    private function assertVisible(Request $request, int $locationId): void
    {
        $ids = $this->authorization->locationIdsWith($request->user(), 'space.manage');

        if ($ids !== null && ! in_array($locationId, $ids, true)) {
            abort(404);
        }
    }

    private function assignmentFor(Request $request, int $id): SpaceAssignment
    {
        $record = $this->spaces->findAssignment($id) ?? abort(404);
        $this->assertVisible($request, (int) $record->space->location_id);

        return $record;
    }

    /** Hata: listeye dön; girdi korunur, formdaki gizli _modal alanı sayesinde ilgili modal yeniden açılır. */
    private function fail(Request $request, string $modal, string $field, string $message): RedirectResponse
    {
        return back()->withErrors([$field => $message])->withInput($request->except('_modal') + ['_modal' => $modal]);
    }

    private function done(Request $request, string $message, ?string $tab = null): RedirectResponse
    {
        return redirect()->route('panel.spaces.index', array_filter(['sekme' => $tab ?? $request->input('_tab')]))->with('status', $message);
    }
}
