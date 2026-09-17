<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\BookingService;
use App\Services\GeoService;
use App\Services\SubscriptionService;
use App\Support\PanelReturn;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Üyelikler (faz 39b, artifact §6) — personel tarafı. subscription.view (global) listeler,
 * subscription.manage (finans) açar/iptal eder/yeniler. Tenant context'siz; kayıt
 * SubscriptionService::findAny ile bulunur (şirket bağlamı yok, izin global).
 */
class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly BookingService $bookings,
        private readonly GeoService $geo,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'sekme' => ['nullable', Rule::in(array_keys(SubscriptionService::TABS))],
            'paket' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:80'],
        ]);
        $tab = $filters['sekme'] ?? 'active';

        return view('panel.subscriptions.index', [
            'rows' => $this->subscriptions->paginateAll(['tab' => $tab, 'plan_id' => $filters['paket'] ?? null, 'q' => $filters['q'] ?? null]),
            'tab' => $tab,
            'tabs' => SubscriptionService::TABS,
            'tabCounts' => $this->subscriptions->tabCounts(),
            'stats' => $this->subscriptions->dashboard(),
            'plans' => $this->subscriptions->plans(),
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        return view('panel.subscriptions.create', [
            'companies' => $this->bookings->companiesForDesk(),
            'plans' => $this->subscriptions->plans(true),
            'locations' => $this->geo->allLocations(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer'],
            'plan_id' => ['required', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'starts_on' => ['required', 'date'],
            'months' => ['required', 'integer', 'min:1', 'max:36'],
            'auto_renew' => ['nullable', 'boolean'],
            'issue_invoice' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $company = $this->bookings->companiesForDesk()->firstWhere('id', (int) $data['company_id']);
        $plan = $this->subscriptions->findPlan((int) $data['plan_id']);

        if ($company === null || $plan === null) {
            return back()->withErrors(['company_id' => 'Şirket ya da paket bulunamadı.'])->withInput();
        }

        try {
            $sub = $this->subscriptions->create($request->user(), $company, $plan, [
                'starts_on' => $data['starts_on'],
                'months' => (int) $data['months'],
                'location_id' => $data['location_id'] ?? null,
                'auto_renew' => $request->boolean('auto_renew'),
                'issue_invoice' => $request->boolean('issue_invoice'),
                'note' => $data['note'] ?? null,
            ]);
        } catch (DomainException $e) {
            return back()->withErrors(['plan_id' => $e->getMessage()])->withInput();
        }

        return PanelReturn::to($request, route('panel.subscriptions.show', $sub), 'Üyelik açıldı: '.$company->legal_name.' · '.$plan->name.'.');
    }

    public function show(int $subscription): View
    {
        return view('panel.subscriptions.show', ['sub' => $this->find($subscription)]);
    }

    public function cancel(Request $request, int $subscription): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);

        try {
            $this->subscriptions->cancel($request->user(), $this->find($subscription), $data['reason']);
        } catch (DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'Üyelik iptal edildi.');
    }

    public function renew(Request $request, int $subscription): RedirectResponse
    {
        $data = $request->validate(['months' => ['required', 'integer', 'min:1', 'max:36']]);

        try {
            $sub = $this->subscriptions->renew($request->user(), $this->find($subscription), (int) $data['months']);
        } catch (DomainException $e) {
            return back()->withErrors(['months' => $e->getMessage()]);
        }

        return back()->with('status', 'Üyelik yenilendi; yeni bitiş '.$sub->ends_on->format('d.m.Y').'.');
    }

    private function find(int $id): Subscription
    {
        return $this->subscriptions->findAny($id) ?? abort(404);
    }
}
