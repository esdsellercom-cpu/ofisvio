<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\Space;
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
    ) {}

    public function index(Request $request): View
    {
        $tab = $request->query('sekme') === 'odalar' ? 'odalar' : 'alanlar';
        $rooms = $tab === 'odalar' ? $this->bookings->allRooms() : collect();

        return view('panel.spaces.index', [
            'tab' => $tab,
            'occupancy' => $this->spaces->occupancy(),
            'byLocation' => $tab === 'alanlar' ? $this->spaces->byLocation() : [],
            'spaces' => $tab === 'alanlar' ? $this->spaces->all()->groupBy('location_id') : collect(),
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
        $record = $this->find($space);
        $canManage = $request->user()->can('space.manage');
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
        $record = $this->find($space);
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
        $record = $this->find($space);
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

    private function find(int $id): Space
    {
        return $this->spaces->find($id) ?? abort(404);
    }
}
