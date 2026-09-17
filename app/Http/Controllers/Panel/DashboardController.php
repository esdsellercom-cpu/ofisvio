<?php

namespace App\Http\Controllers\Panel;

use App\Enums\CompanyStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\CompanyService;
use App\Services\KycService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Organizasyon dashboard'u (faz 38; audit P1-13 ile sadeleşti): aktif organizasyondaki şirketler ve KYC
 * durumu (CompanyService görebildikleriyle sınırlar). Personelin organizasyondan bağımsız operasyon özeti
 * ayrı: OperationsDashboardController (/panel/operasyon). Route: auth + tenant; ek izin yok.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly CompanyService $companies,
        private readonly KycService $kyc,
    ) {}

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $companies = $this->companies->visibleTo($user);

        $summaries = $this->kyc->statusSummaries($companies); // tek sorgu, şirket başına değil

        $rows = $companies->map(fn (Company $company) => [
            'company' => $company,
            'kyc' => $summaries[$company->id],
        ]);

        return view('panel.dashboard', [
            'rows' => $rows,
            'counts' => [
                'total' => $companies->count(),
                'active' => $companies->filter(fn (Company $c) => $c->status === CompanyStatus::ACTIVE)->count(),
                'in_kyc' => $companies->filter(fn (Company $c) => in_array($c->status, [
                    CompanyStatus::REGISTERED, CompanyStatus::KYC_PENDING, CompanyStatus::KYC_REVIEW,
                ], true))->count(),
            ],
        ]);
    }
}
