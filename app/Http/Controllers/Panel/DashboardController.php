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
 * Genel bakış — aktif organizasyondaki şirketler ve KYC durumu.
 * Route: auth + tenant. Ek izin yok; liste CompanyService tarafından
 * kullanıcının görebildikleriyle sınırlanır.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly CompanyService $companies,
        private readonly KycService $kyc,
    ) {}

    public function __invoke(Request $request): View
    {
        $companies = $this->companies->visibleTo($request->user());

        $rows = $companies->map(fn (Company $company) => [
            'company' => $company,
            'kyc' => $this->kyc->statusSummary($company),
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
