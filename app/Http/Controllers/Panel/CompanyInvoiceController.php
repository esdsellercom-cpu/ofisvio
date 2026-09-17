<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\InvoiceService;
use App\Services\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** Müşteri tarafı faturalar (invoice.view, company kapsamı): yayınlanmış faturalar ve ödemeleri; taslak görünmez. */
class CompanyInvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices, private readonly TenantContext $context) {}

    public function index(Request $request, Company $company): View
    {
        $this->context->toArray($request->user(), $company->id);

        return view('panel.companies.invoices', ['company' => $company, 'invoices' => $this->invoices->forCompany($company)]);
    }

    public function show(Request $request, Company $company, int $invoice): View
    {
        $this->context->toArray($request->user(), $company->id);

        return view('panel.companies.invoice', ['company' => $company, 'invoice' => $this->invoices->findForCompany($company, $invoice) ?? abort(404)]);
    }
}
