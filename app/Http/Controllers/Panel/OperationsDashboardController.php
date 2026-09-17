<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\BookingService;
use App\Services\EventService;
use App\Services\FranchiseService;
use App\Services\GeoService;
use App\Services\InvoiceService;
use App\Services\KycQueueService;
use App\Services\LeadService;
use App\Services\NotificationService;
use App\Services\SpaceService;
use App\Services\SubscriptionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Operasyon paneli (audit P1-13 / M-3): personelin organizasyondan BAĞIMSIZ özeti — rezervasyon,
 * tahsilat, üyelik, alan doluluğu, talepler, etkinlik, franchise, bildirim, KYC. Tenant context yok;
 * her blok yalnız ilgili izinle istenir (izinsiz blok için sorgu açılmaz). Sahte sayaç yok.
 */
class OperationsDashboardController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly LeadService $leads,
        private readonly NotificationService $notifications,
        private readonly GeoService $geo,
        private readonly KycQueueService $kycQueue,
        private readonly SubscriptionService $subscriptions,
        private readonly InvoiceService $invoices,
        private readonly EventService $events,
        private readonly FranchiseService $franchise,
        private readonly SpaceService $spaces,
    ) {}

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $booking = $user->can('booking.view');
        $locations = $user->can('geo.view') ? $this->geo->allLocations() : null;

        return view('panel.operations', ['ops' => [
            'bookings' => $booking ? $this->bookings->dashboard() : null,
            'today' => $booking ? collect($this->bookings->paginateAll(['tab' => 'today'], 8)->items()) : null,
            'pending' => $booking ? collect($this->bookings->paginateAll(['tab' => 'pending'], 6)->items()) : null,
            'leads' => $user->can('lead.view') ? $this->leads->paginate(['status' => 'new'], 6) : null,
            'notifications' => $user->can('notification.view') ? $this->notifications->counts() : null,
            'kyc_pending' => $user->can('kyc.view_status') ? array_sum($this->kycQueue->pendingCounts($user)) : null,
            'subscriptions' => $user->can('subscription.view') ? $this->subscriptions->dashboard() : null,
            'expiring' => $user->can('subscription.view') ? collect($this->subscriptions->paginateAll(['tab' => 'expiring'], 6)->items()) : null,
            'finance' => $user->can('invoice.view') ? $this->invoices->dashboard() : null,
            'overdue' => $user->can('invoice.view') ? collect($this->invoices->paginateAll(['tab' => 'overdue'], 6)->items()) : null,
            'events' => $user->can('event.view') ? $this->events->dashboard() : null,
            'franchise' => $user->can('franchise.view') ? $this->franchise->counts() : null,
            'spaces' => $user->can('space.view') ? $this->spaces->occupancy() : null,
            'locations' => $locations === null ? null : ['total' => $locations->count(), 'published' => $locations->where('is_published', true)->where('is_active', true)->count()],
        ]]);
    }
}
