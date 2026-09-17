<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\SubscriptionService;
use App\Services\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** Müşteri tarafı üyelik görünümü (subscription.view, company kapsamı). Salt okunur; satın alma/yenileme personel + ödeme modülüyle. */
class CompanySubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions, private readonly TenantContext $context) {}

    public function index(Request $request, Company $company): View
    {
        $this->context->toArray($request->user(), $company->id);

        return view('panel.companies.subscriptions', ['company' => $company, 'subs' => $this->subscriptions->forCompany($company)]);
    }
}
