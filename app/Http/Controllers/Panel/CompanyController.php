<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCompanyRequest;
use App\Models\Company;
use App\Services\CompanyActivationService;
use App\Services\CompanyService;
use App\Services\KycService;
use App\Services\TenantContext;
use App\Support\ActivationJourney;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Şirketler (F3). Yetki route'ta:
 *   index         — auth + tenant (liste servis tarafından süzülür)
 *   create/store  — permission:organization.manage
 *   show          — permission:company.view,company
 */
class CompanyController extends Controller
{
    public function __construct(
        private readonly CompanyService $companies,
        private readonly CompanyActivationService $activation,
        private readonly KycService $kyc,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): View
    {
        return view('panel.companies.index', [
            'companies' => $this->companies->visibleTo($request->user()),
        ]);
    }

    public function create(): View
    {
        return view('panel.companies.create');
    }

    public function store(StoreCompanyRequest $request): RedirectResponse
    {
        $company = $this->companies->create($request->user(), $request->validated());

        return redirect()
            ->route('panel.companies.show', $company)
            ->with('status', $company->legal_name.' açıldı. Sıradaki adım: KYC belgelerini yükleyin.');
    }

    /** company.update: künye (unvan, vergi no). */
    public function update(StoreCompanyRequest $request, Company $company): RedirectResponse
    {
        $this->context->toArray($request->user(), $company->id);
        $this->companies->updateProfile($company, $request->validated());

        return redirect()->route('panel.companies.show', $company)->with('status', 'Şirket künyesi güncellendi.');
    }

    public function show(Request $request, Company $company): View
    {
        // toArray şirketi aktif organizasyona karşı doğrular (404 aksi halde).
        $this->context->toArray($request->user(), $company->id);

        return view('panel.companies.show', [
            'company' => $company,
            'kyc' => $this->kyc->statusSummary($company),
            'history' => $this->activation->history($company),
            'journey' => ActivationJourney::steps(),
        ]);
    }
}
