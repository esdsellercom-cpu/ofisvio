<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\ServiceService;
use App\Services\SubscriptionService;
use App\Support\Money;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Paketler (faz 39b): subscription.view listeler, subscription.manage yazar. Kodda/seed'de paket yok. */
class PlanController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions, private readonly ServiceService $services) {}

    public function index(): View
    {
        return view('panel.plans.index', ['plans' => $this->subscriptions->plans()]);
    }

    public function create(): View
    {
        return view('panel.plans.form', ['plan' => null, 'periods' => Plan::PERIODS, 'services' => $this->services->all()]);
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $plan = $this->subscriptions->createPlan($request->user(), $this->validated($request));
        } catch (DomainException $e) {
            return back()->withErrors(['name' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.plans.index')->with('status', $plan->name.' paketi eklendi.');
    }

    public function edit(Plan $plan): View
    {
        return view('panel.plans.form', ['plan' => $plan, 'periods' => Plan::PERIODS, 'services' => $this->services->all()]);
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        try {
            $this->subscriptions->updatePlan($request->user(), $plan, $this->validated($request));
        } catch (DomainException $e) {
            return back()->withErrors(['name' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.plans.index')->with('status', $plan->name.' güncellendi.');
    }

    public function destroy(Request $request, Plan $plan): RedirectResponse
    {
        try {
            $this->subscriptions->deletePlan($request->user(), $plan);
        } catch (DomainException $e) {
            return back()->withErrors(['plan' => $e->getMessage()]);
        }

        return redirect()->route('panel.plans.index')->with('status', 'Paket silindi.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'summary' => ['nullable', 'string', 'max:300'],
            'features' => ['nullable', 'string', 'max:3000'],
            'price' => ['required', Money::RULE],
            'period' => ['required', Rule::in(array_keys(Plan::PERIODS))],
            'service_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]) + ['is_active' => $request->boolean('is_active')];
    }
}
