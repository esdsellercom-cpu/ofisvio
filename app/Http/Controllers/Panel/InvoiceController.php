<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\RequestJitAccessRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\BookingService;
use App\Services\InvoiceService;
use App\Services\JitAccessService;
use App\Services\SettingsService;
use App\Services\SubscriptionService;
use App\Support\Money;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ödemeler & faturalandırma (faz 39c, artifact §8) — personel tarafı. invoice.view (global)
 * listeler; invoice.issue taslak açar/yayınlar; invoice.cancel iptal eder;
 * payment_allocation.manage tahsilat kaydeder. Tenant context'siz; kayıt findAny ile.
 */
class InvoiceController extends Controller
{
    /** JIT kaynak tipi: fatura iptali (invoice.cancel, requires_jit) fatura başına grant ister. */
    public const RESOURCE = 'invoice';

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly JitAccessService $jit,
        private readonly BookingService $bookings,
        private readonly SubscriptionService $subscriptions,
        private readonly SettingsService $settings,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate(['sekme' => ['nullable', Rule::in(array_keys(InvoiceService::TABS))], 'q' => ['nullable', 'string', 'max:80']]);
        $tab = $filters['sekme'] ?? 'open';

        return view('panel.invoices.index', [
            'rows' => $this->invoices->paginateAll(['tab' => $tab, 'q' => $filters['q'] ?? null]),
            'tab' => $tab,
            'tabs' => InvoiceService::TABS,
            'tabCounts' => $this->invoices->tabCounts(),
            'stats' => $this->invoices->dashboard(),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request): View
    {
        $companyId = (int) $request->query('sirket', 0);

        return view('panel.invoices.create', [
            'companies' => $this->bookings->companiesForDesk(),
            'companyId' => $companyId,
            'subscriptions' => $companyId > 0 ? $this->subscriptions->forCompanyAny($companyId) : collect(),
            'taxRate' => $this->settings->int('finance.default_tax_rate'),
            'dueDays' => $this->settings->int('finance.due_days'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer'],
            'subscription_id' => ['nullable', 'integer'],
            'description' => ['required', 'string', 'max:300'],
            'subtotal' => ['required', Money::RULE],
            'tax_rate' => ['nullable', 'integer', 'min:0', 'max:100'],
            'due_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
            'issue' => ['nullable', 'boolean'],
        ]);

        $company = $this->bookings->companiesForDesk()->firstWhere('id', (int) $data['company_id']);

        if ($company === null) {
            return back()->withErrors(['company_id' => 'Şirket bulunamadı ya da fatura kesilemez durumda.'])->withInput();
        }

        try {
            $invoice = $this->invoices->create($request->user(), $company, [
                'description' => $data['description'],
                'subtotal' => $data['subtotal'],
                'tax_rate' => $data['tax_rate'] ?? null,
                'due_on' => $data['due_on'] ?? null,
                'subscription_id' => $data['subscription_id'] ?? null,
                'note' => $data['note'] ?? null,
                'issue' => $request->boolean('issue'),
            ]);
        } catch (DomainException $e) {
            return back()->withErrors(['subtotal' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.invoices.show', $invoice)->with('status', $invoice->status === 'issued' ? 'Fatura yayınlandı: '.$invoice->number : 'Taslak fatura oluşturuldu.');
    }

    public function show(Request $request, int $invoice): View
    {
        $record = $this->find($invoice);

        return view('panel.invoices.show', [
            'invoice' => $record,
            'methods' => Payment::METHODS,
            // İptal JIT'li (matris: invoice.cancel requires_jit): grant açıksa form, rolde varsa JIT isteği.
            'canCancel' => $this->jit->allows($request->user(), 'invoice.cancel', [], self::RESOURCE, $record->id),
            'mayRequestCancel' => $request->user()->can('invoice.cancel'),
        ]);
    }

    /** JIT: fatura iptali için süreli, gerekçeli erişim (denetim kaydına düşer). */
    public function requestJit(RequestJitAccessRequest $request, int $invoice): RedirectResponse
    {
        $record = $this->find($invoice);
        $v = $request->validated();
        $grantId = $this->jit->grant($request->user(), 'invoice.cancel', [], self::RESOURCE, $record->id, $v['reason'], null, (int) $v['ttl_minutes']);

        if ($grantId === null) {
            return back()->withErrors(['reason' => 'JIT erişimi açılamadı: rolünüz invoice.cancel taşımıyor.']);
        }

        return back()->with('status', 'Fatura iptali için '.$v['ttl_minutes'].' dakikalık erişim açıldı.');
    }

    public function issue(Request $request, int $invoice): RedirectResponse
    {
        try {
            $inv = $this->invoices->issue($request->user(), $this->find($invoice));
        } catch (DomainException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()]);
        }

        return back()->with('status', 'Fatura yayınlandı: '.$inv->number);
    }

    public function cancel(Request $request, int $invoice): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);

        try {
            $this->invoices->cancel($request->user(), $this->find($invoice), $data['reason']);
        } catch (DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'Fatura iptal edildi.');
    }

    public function payment(Request $request, int $invoice): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', Money::RULE],
            'method' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'paid_on' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            $this->invoices->recordPayment($request->user(), $this->find($invoice), $data);
        } catch (DomainException $e) {
            return back()->withErrors(['amount' => $e->getMessage()])->withInput();
        }

        return back()->with('status', 'Tahsilat kaydedildi.');
    }

    private function find(int $id): Invoice
    {
        return $this->invoices->findAny($id) ?? abort(404);
    }
}
