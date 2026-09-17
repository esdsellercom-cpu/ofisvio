<?php

namespace App\Http\Controllers\Panel;

use App\Enums\CompanyStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\BookingService;
use App\Services\CompanyService;
use App\Services\GeoService;
use App\Services\KycQueueService;
use App\Services\KycService;
use App\Services\LeadService;
use App\Services\NotificationService;
use App\Services\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Genel bakış (faz 38 — "Kolektif Panel" dashboard'u).
 *
 * Müşteri: aktif organizasyondaki şirketler ve KYC durumu (CompanyService görebildikleriyle sınırlar).
 * Personel: ek olarak operasyon özeti — KPI şeridi ve kartlar, hepsi gerçek servis toplamları
 * (BookingService::dashboard, LeadService, NotificationService::counts, KycQueueService, GeoService);
 * blok yalnız ilgili izin varsa istenir, izinsiz blok için sorgu açılmaz. Sahte sayaç yok.
 *
 * Route: auth + tenant. Ek izin yok; bloklar izne göre.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CompanyService $companies,
        private readonly KycService $kyc,
        private readonly KycQueueService $kycQueue,
        private readonly BookingService $bookings,
        private readonly LeadService $leads,
        private readonly NotificationService $notifications,
        private readonly GeoService $geo,
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
            'ops' => $this->context->isInternalStaff($user) ? $this->operations($request) : null,
        ]);
    }

    /**
     * Personel operasyon özeti; her blok izne bağlı (null = blok yok).
     *
     * @return array<string, mixed>
     */
    private function operations(Request $request): array
    {
        $user = $request->user();
        $booking = $user->can('booking.view');

        $locations = $user->can('geo.view') ? $this->geo->allLocations() : null;

        return [
            'bookings' => $booking ? $this->bookings->dashboard() : null,
            'today' => $booking ? collect($this->bookings->paginateAll(['tab' => 'today'], 8)->items()) : null,
            'pending' => $booking ? collect($this->bookings->paginateAll(['tab' => 'pending'], 6)->items()) : null,
            'leads' => $user->can('lead.view') ? $this->leads->paginate(['status' => 'new'], 6) : null,
            'notifications' => $user->can('notification.view') ? $this->notifications->counts() : null,
            'kyc_pending' => $user->can('kyc.view_status') ? array_sum($this->kycQueue->pendingCounts($user)) : null,
            'locations' => $locations === null ? null : [
                'total' => $locations->count(),
                'published' => $locations->where('is_published', true)->where('is_active', true)->count(),
            ],
        ];
    }
}
