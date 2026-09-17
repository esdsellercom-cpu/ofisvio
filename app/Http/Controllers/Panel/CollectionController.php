<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\InvoiceService;
use App\Services\SubscriptionService;
use Illuminate\Contracts\View\View;

/**
 * Tahsilat & üyelik takibi (faz 39c, artifact §7): gecikmiş/yaklaşan vadeler, bitişi yaklaşan
 * üyelikler, ay tahsilatı — hepsi canlı toplam. invoice.view (global).
 */
class CollectionController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices, private readonly SubscriptionService $subscriptions) {}

    public function index(): View
    {
        return view('panel.collections.index', [
            'stats' => $this->invoices->dashboard(),
            'open' => $this->invoices->collectionList(),
            'subscriptions' => $this->subscriptions->dashboard(),
            'expiring' => collect($this->subscriptions->paginateAll(['tab' => 'expiring'], 20)->items()),
            'monthly' => $this->invoices->monthlyRevenue(6),
        ]);
    }
}
