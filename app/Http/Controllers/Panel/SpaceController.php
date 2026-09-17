<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\Space;
use App\Services\AuthorizationService;
use App\Services\BookingService;
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
    public function __construct(
        private readonly SpaceService $spaces,
        private readonly BookingService $bookings,
        private readonly SubscriptionService $subscriptions,
        private readonly MembershipService $members,
        private readonly AuthorizationService $authorization,
    ) {}

    public function index(Request $request): View
    {
        $tab = $request->query('sekme') === 'odalar' ? 'odalar' : 'alanlar';
        // Lokasyon kapsamlı personel (audit: location-based access): yalnız kendi lokasyonları; global → null (hepsi).
        $scope = $this->visibleLocationIds($request);
        $rooms = $tab === 'odalar' ? $this->bookings->allRooms()->when($scope !== null, fn ($c) => $c->whereIn('location_id', $scope)) : collect();

        return view('panel.spaces.index', [
            'tab' => $tab,
            'occupancy' => $this->spaces->occupancy(null, $scope),
            'byLocation' => $tab === 'alanlar' ? $this->spaces->byLocation($scope) : [],
            'spaces' => $tab === 'alanlar' ? $this->spaces->all($scope)->groupBy('location_id') : collect(),
            'scoped' => $scope !== null,
            'roomsByLocation' => $rooms->groupBy(fn (Room $r) => $r->location->name),
            'kinds' => Room::KINDS,
            'spaceKinds' => Space::KINDS,
            'roomCounts' => [
                'total' => $rooms->count(),
                'active' => $rooms->where('is_active', true)->count(),
                'capacity' => (int) $rooms->where('is_active', true)->sum('capacity'),
                'locations' => $rooms->pluck('location_id')->unique()->count(),
            ],
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
