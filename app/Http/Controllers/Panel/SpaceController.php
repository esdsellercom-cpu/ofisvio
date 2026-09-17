<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Room;
use App\Models\Space;
use App\Services\AssetService;
use App\Services\AuthorizationService;
use App\Services\BookingService;
use App\Services\GeoService;
use App\Services\LocationMediaService;
use App\Services\MembershipService;
use App\Services\SpaceService;
use App\Services\SubscriptionService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Masalar, ofisler & odalar (audit P0-2, artifact §3): tüm lokasyonların masa/ofis envanteri
 * ve doluluğu (sekme "alanlar"), saatlik odalar (sekme "odalar"). Alan detayı: tahsis geçmişi,
 * tahsis etme/sonlandırma (space.manage). Envanter düzenleme lokasyon ekranında (geo.edit).
 */
class SpaceController extends Controller
{
    public const TABS = ['tum', 'masalar', 'ofisler', 'odalar', 'demirbas', 'tahsisler'];

    public const TAB_LABELS = ['tum' => 'Tüm envanter', 'masalar' => 'Masalar', 'ofisler' => 'Ofisler', 'odalar' => 'Odalar', 'demirbas' => 'Demirbaşlar', 'tahsisler' => 'Tahsisler'];

    /** Durum süzgeci → kart durumu. */
    public const STATUS_MAP = ['musait' => 'available', 'tahsisli' => 'assigned', 'bakimda' => 'maintenance', 'pasif' => 'inactive'];

    public function __construct(
        private readonly SpaceService $spaces,
        private readonly BookingService $bookings,
        private readonly SubscriptionService $subscriptions,
        private readonly MembershipService $members,
        private readonly AuthorizationService $authorization,
        private readonly AssetService $assets,
        private readonly GeoService $geo,
        private readonly LocationMediaService $media,
    ) {}

    /**
     * Envanter ekranı (faz 46): sekmeler tum | masalar | ofisler | odalar | demirbas | tahsisler;
     * durum süzgeci musait | tahsisli | bakimda | pasif; lokasyon ve arama. Modaller için seçenek listeleri
     * (lokasyon, şirket, üye, üyelik, galeri, müsait demirbaş) aynı yanıtta gelir — JS lokasyon/şirkete göre süzer.
     */
    public function index(Request $request): View
    {
        $tab = in_array($request->query('sekme'), self::TABS, true) ? (string) $request->query('sekme') : 'tum';
        $status = in_array($request->query('durum'), array_keys(self::STATUS_MAP), true) ? (string) $request->query('durum') : null;
        $locationId = (int) $request->query('lokasyon', 0) ?: null;
        $q = mb_strtolower(trim((string) $request->query('q', '')));
        // Lokasyon kapsamlı personel (audit: location-based access): yalnız kendi lokasyonları; global → null (hepsi).
        $scope = $this->visibleLocationIds($request);
        $canManage = $this->authorization->canAnywhere($request->user(), 'space.manage');
        $canRooms = $this->authorization->can($request->user(), 'geo.edit');
        $wanted = $status !== null ? self::STATUS_MAP[$status] : null;

        $all = $this->spaces->all($scope);
        $allRooms = $this->bookings->allRooms()->when($scope !== null, fn ($c) => $c->whereIn('location_id', $scope))->values();

        $spaces = $all
            ->when($tab === 'masalar', fn ($c) => $c->whereIn('kind', Space::DESK_KINDS))
            ->when($tab === 'ofisler', fn ($c) => $c->whereIn('kind', ['office', 'other']))
            ->when($locationId !== null, fn ($c) => $c->where('location_id', $locationId))
            ->when($wanted !== null, fn ($c) => $c->filter(fn (Space $s) => $s->inventoryStatus() === $wanted))
            ->when($q !== '', fn ($c) => $c->filter(fn (Space $s) => str_contains(mb_strtolower($s->name.' '.$s->code.' '.$s->location->name.' '.$s->activeAssignments->map(fn ($a) => ($a->user !== null ? $a->user->name : '').' '.($a->company !== null ? $a->company->legal_name : ''))->implode(' ')), $q)))
            ->values();
        $rooms = in_array($tab, ['tum', 'odalar'], true)
            ? $allRooms
                ->when($locationId !== null, fn ($c) => $c->where('location_id', $locationId))
                ->when($wanted !== null, fn ($c) => $c->filter(fn (Room $r) => ['active' => 'available', 'maintenance' => 'maintenance', 'inactive' => 'inactive'][$r->operationalStatus()] === $wanted))
                ->when($q !== '', fn ($c) => $c->filter(fn (Room $r) => str_contains(mb_strtolower($r->name.' '.$r->code.' '.$r->location->name), $q)))
                ->values()
            : collect();
        $assets = $tab === 'demirbas' ? $this->assets->all($scope, ['location_id' => $locationId, 'q' => $q, 'status' => $status !== null ? ['musait' => 'available', 'tahsisli' => 'assigned', 'bakimda' => 'maintenance', 'pasif' => 'retired'][$status] : null]) : collect();
        $assignments = $tab === 'tahsisler' ? $this->spaces->activeAssignments($scope)->when($locationId !== null, fn ($c) => $c->filter(fn ($a) => (int) $a->space->location_id === $locationId))->values() : collect();

        $locations = $this->geo->allLocations()->when($scope !== null, fn ($c) => $c->whereIn('id', $scope))->values();
        $companies = $canManage ? $this->bookings->companiesForDesk() : collect();

        return view('panel.spaces.index', [
            'tab' => $tab,
            'status' => $status,
            'locationId' => $locationId,
            'q' => $q,
            'tabs' => self::TAB_LABELS,
            'occupancy' => $this->spaces->occupancy(null, $scope),
            'spaces' => in_array($tab, ['tum', 'masalar', 'ofisler'], true) ? $spaces : collect(),
            'rooms' => $rooms,
            'assets' => $assets,
            'assignments' => $assignments,
            'counts' => [
                'tum' => $all->count() + $allRooms->count(),
                'masalar' => $all->whereIn('kind', Space::DESK_KINDS)->count(),
                'ofisler' => $all->whereIn('kind', ['office', 'other'])->count(),
                'odalar' => $allRooms->count(),
                'demirbas' => $this->assets->all($scope)->count(),
                'tahsisler' => $all->sum(fn (Space $s) => $s->activeAssignments->count()),
            ],
            'scoped' => $scope !== null,
            'canManage' => $canManage,
            'canRooms' => $canRooms,
            'locations' => $locations,
            'companies' => $companies,
            'members' => $canManage ? $this->members->membersOfCompanies($companies)->filter(fn ($m) => $m->isActive() && $m->user !== null)->values() : collect(),
            'subscriptions' => $canManage ? $this->subscriptions->activeForCompanies($companies->pluck('id')->map(fn ($id) => (int) $id)->all()) : collect(),
            'gallery' => $canManage || $canRooms ? $this->media->linksFor($scope)->filter(fn ($l) => $l->media !== null)->values() : collect(),
            'assignableAssets' => $canManage ? $this->assets->assignable($scope) : collect(),
            'allSpaces' => $all,
            'assignableSpaces' => $all->filter(fn (Space $s) => $s->inventoryStatus() === 'available')->values(),
            'spaceKinds' => Space::KINDS,
            'roomKinds' => Room::KINDS,
            'assetCategories' => Asset::CATEGORIES,
            'assetStatuses' => Asset::STATUSES,
            'openModal' => old('_modal', $request->query('modal')),
        ]);
    }

    /** Detay: tahsis geçmişi; ?sirket= seçilince o şirketin üyeleri/aktif üyelikleri forma gelir (JS'siz). */
    public function show(Request $request, int $space): View
    {
        $record = $this->find($space, $request);
        $canManage = $this->authorization->can($request->user(), 'space.manage', ['location_id' => $record->location_id]);
        $company = $canManage ? $this->bookings->companiesForDesk()->firstWhere('id', (int) $request->query('sirket', 0)) : null;

        return view('panel.spaces.show', [
            'space' => $record,
            'assignments' => $this->spaces->assignmentsOf($record),
            'companies' => $canManage ? $this->bookings->companiesForDesk() : collect(),
            'canManage' => $canManage,
            'selectedCompany' => $company,
            'members' => $company ? $this->members->membersOf($company)->filter(fn ($m) => $m->isActive())->values() : collect(),
            'subscriptions' => $company ? $this->subscriptions->forCompanyAny($company->id)->where('status', 'active')->values() : collect(),
        ]);
    }

    public function assign(Request $request, int $space): RedirectResponse
    {
        $record = $this->find($space, $request, 'space.manage');
        $data = $request->validate([
            'company_id' => ['required', 'integer'],
            'subscription_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $company = $this->bookings->companiesForDesk()->firstWhere('id', (int) $data['company_id']);

        if ($company === null) {
            return back()->withErrors(['company_id' => 'Şirket bulunamadı ya da tahsis alamaz durumda.'])->withInput();
        }

        // Üye seçildiyse şirketin aktif üyesi olmalı (başka şirketin kullanıcısı masaya yazılamaz).
        if (! empty($data['user_id']) && ! $this->members->membersOf($company)->contains(fn ($m) => $m->isActive() && (int) $m->user_id === (int) $data['user_id'])) {
            return back()->withErrors(['user_id' => 'Seçilen kişi bu şirketin aktif üyesi değil.'])->withInput();
        }

        try {
            $this->spaces->assign($request->user(), $record, $company, $data);
        } catch (DomainException $e) {
            return back()->withErrors(['company_id' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.spaces.show', $record->id)->with('status', $record->name.' → '.$company->legal_name.' tahsis edildi.');
    }

    public function end(Request $request, int $space, int $assignment): RedirectResponse
    {
        $record = $this->find($space, $request, 'space.manage');
        $target = $this->spaces->findAssignment($assignment);

        if ($target === null || (int) $target->space_id !== (int) $record->id) {
            abort(404);
        }

        try {
            $this->spaces->end($request->user(), $target);
        } catch (DomainException $e) {
            return back()->withErrors(['assignment' => $e->getMessage()]);
        }

        return redirect()->route('panel.spaces.show', $record->id)->with('status', 'Tahsis sonlandırıldı.');
    }

    /**
     * Alanı bulur; lokasyon kapsamlı kullanıcı için alan kendi lokasyonlarından değilse 404
     * (varlık sızdırılmaz). $permission: hangi iznin lokasyon kapsamına bakılacağı.
     */
    private function find(int $id, ?Request $request = null, string $permission = 'space.view'): Space
    {
        $space = $this->spaces->find($id) ?? abort(404);

        if ($request !== null) {
            $ids = $this->authorization->locationIdsWith($request->user(), $permission);
            // space.view global değilse: geo.view/booking.view global olan personel de tüm alanları görür.
            $globalAlt = $permission === 'space.view' && ($this->authorization->can($request->user(), 'geo.view') || $this->authorization->can($request->user(), 'booking.view'));

            if ($ids !== null && ! $globalAlt && ! in_array((int) $space->location_id, $ids, true)) {
                abort(404);
            }
        }

        return $space;
    }

    /** null = tüm lokasyonlar (global izin); dizi = lokasyon kapsamlı personelin lokasyonları. */
    private function visibleLocationIds(Request $request): ?array
    {
        $user = $request->user();

        foreach (['space.view', 'geo.view', 'booking.view'] as $permission) {
            if ($this->authorization->locationIdsWith($user, $permission) === null) {
                return null;
            }
        }

        return array_values(array_unique(array_merge($this->authorization->locationIdsWith($user, 'space.view') ?? [], $this->authorization->locationIdsWith($user, 'booking.view') ?? [])));
    }
}
