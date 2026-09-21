<?php

namespace App\Http\Controllers\Panel;

use App\Documents\DocumentTemplates;
use App\Http\Controllers\Controller;
use App\Http\Requests\RequestJitAccessRequest;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Services\AuthorizationService;
use App\Services\DocumentService;
use App\Services\InvoiceService;
use App\Services\JitAccessService;
use App\Services\SubscriptionService;
use App\Support\Money;
use App\Support\PanelReturn;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Tahsilat & belge merkezi (faz 39c → faz 47): tek ekrandan manuel tahsilat, tahsilat iptali (silme yok),
 * makbuz, geciken ödemeler ve geciken ödeme belgesi, belge şablonları (canlı önizleme), PDF/yazdırma.
 *
 * Okuma invoice.view (global); yazma payment_allocation.manage; şablon invoice.issue. Yetki route'ta.
 */
class CollectionController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly SubscriptionService $subscriptions,
        private readonly DocumentService $documents,
        private readonly AuthorizationService $authorization,
        private readonly JitAccessService $jit,
    ) {}

    /** Defter (audit F-15): ledger.view global ise tüm şirketler, değilse tenant scope. */
    public function ledger(Request $request): View
    {
        $global = $this->authorization->can($request->user(), 'ledger.view');

        return view('panel.collections.ledger', [
            'entries' => $this->invoices->ledgerEntries(['type' => (string) $request->query('tur', '')], 50, $global),
            'types' => LedgerEntry::TYPES,
            'type' => (string) $request->query('tur', ''),
            'canCorrect' => $this->authorization->can($request->user(), 'ledger.correction_entry'),
        ]);
    }

    /** Düzeltme için JIT (ledger.correction_entry fatura başına, gerekçeli, süreli). */
    public function ledgerJit(RequestJitAccessRequest $request, int $invoice): RedirectResponse
    {
        $record = $this->invoices->findAny($invoice) ?? abort(404);
        $v = $request->validated();
        $grantId = $this->jit->grant($request->user(), 'ledger.correction_entry', [], 'invoice', $record->id, $v['reason'], null, (int) $v['ttl_minutes']);

        if ($grantId === null) {
            return back()->withErrors(['reason' => 'JIT erişimi açılamadı: rolünüz ledger.correction_entry taşımıyor.']);
        }

        return back()->with('status', 'Düzeltme kaydı için '.$v['ttl_minutes'].' dakikalık erişim açıldı.');
    }

    public function ledgerCorrection(Request $request, int $invoice): RedirectResponse
    {
        $record = $this->invoices->findAny($invoice) ?? abort(404);
        $data = $request->validate(['amount' => ['required', Money::RULE], 'direction' => ['required', 'in:debit,credit'], 'memo' => ['required', 'string', 'max:200']]);
        $amount = Money::parse((string) $data['amount']) * ($data['direction'] === 'credit' ? -1 : 1);

        try {
            $this->invoices->correction($request->user(), $record, $amount, (string) $data['memo']);
        } catch (DomainException $e) {
            return back()->withErrors(['memo' => $e->getMessage()]);
        }

        return back()->with('status', 'Düzeltme kaydı eklendi.');
    }

    public function index(Request $request): View
    {
        // Mevcut takip ekranı (faz 39c) aynen; faz 47 yalnız aksiyonlar ve alttaki iki bölümü ekler.
        return view('panel.collections.index', [
            'stats' => $this->invoices->dashboard(),
            'open' => $this->invoices->collectionList(),
            'subscriptions' => $this->subscriptions->dashboard(),
            'expiring' => collect($this->subscriptions->paginateAll(['tab' => 'expiring'], 20)->items()),
            'monthly' => $this->invoices->monthlyRevenue(6),
            'payments' => $this->invoices->payments([], 30),
            'documentsList' => $this->documents->all([], 30),
            'openInvoices' => $this->invoices->openForPayment(),
            'methods' => Payment::METHODS,
            'kinds' => DocumentTemplates::KINDS,
            'openModal' => old('_modal', $request->query('modal')),
            'currency' => Money::currency(),
        ]);
    }

    // ---- Manuel tahsilat ---------------------------------------------------------

    /** Kaydet / Kaydet ve makbuz oluştur (then=receipt). Tutar büyük birim; para birimi faturayla aynı olmalı. */
    public function storePayment(Request $request): RedirectResponse
    {
        $v = $request->validate([
            'invoice_id' => ['required', 'integer'],
            'company_id' => ['nullable', 'integer'],
            'amount' => ['required', Money::RULE],
            'currency' => ['nullable', 'string', 'size:3'],
            'method' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:200'],
            'reference' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:300'],
            'then' => ['nullable', Rule::in(['receipt'])],
        ]);
        $invoice = $this->invoices->findAny((int) $v['invoice_id']);

        if ($invoice === null || (! empty($v['company_id']) && (int) $invoice->company_id !== (int) $v['company_id'])) {
            return back()->withErrors(['invoice_id' => 'Fatura bulunamadı ya da seçilen müşteriye ait değil.'])->withInput($request->except('_modal') + ['_modal' => 'payment']);
        }

        try {
            $payment = $this->invoices->recordPayment($request->user(), $invoice, $v);
        } catch (DomainException $e) {
            return back()->withErrors(['amount' => $e->getMessage()])->withInput($request->except('_modal') + ['_modal' => 'payment']);
        }

        if (($v['then'] ?? null) === 'receipt') {
            $document = $this->documents->createReceipt($request->user(), $payment);

            return redirect()->route('panel.collections.documents.show', $document)->with('status', 'Tahsilat kaydedildi; makbuz '.$document->number.' oluşturuldu.');
        }

        return PanelReturn::to($request, route('panel.collections.index').'#tahsilatlar', Money::format($payment->amount, $invoice->currency).' tahsilat kaydedildi ('.$payment->methodLabel().'); fatura bakiyesi güncellendi.');
    }

    public function cancelPayment(Request $request, int $payment): RedirectResponse
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']])['reason'];
        $record = $this->invoices->findPayment($payment) ?? abort(404);

        try {
            $this->invoices->cancelPayment($request->user(), $record, $reason);
        } catch (DomainException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        return redirect()->to(route('panel.collections.index').'#tahsilatlar')->with('status', 'Tahsilat iptal edildi; fatura bakiyesi geri alındı, kayıt geçmişte kaldı.');
    }

    public function receipt(Request $request, int $payment): RedirectResponse
    {
        $record = $this->invoices->findPayment($payment) ?? abort(404);

        try {
            $document = $this->documents->createReceipt($request->user(), $record);
        } catch (DomainException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        return PanelReturn::to($request, route('panel.collections.documents.show', $document), 'Makbuz '.$document->number.' hazır.');
    }

    public function overdueNotice(Request $request, int $invoice): RedirectResponse
    {
        $record = $this->invoices->findAny($invoice) ?? abort(404);

        try {
            $document = $this->documents->createOverdueNotice($request->user(), $record);
        } catch (DomainException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()]);
        }

        return PanelReturn::to($request, route('panel.collections.documents.show', $document), 'Geciken ödeme belgesi '.$document->number.' oluşturuldu.');
    }

    // ---- Belgeler ------------------------------------------------------------------

    public function showDocument(Request $request, int $document): View
    {
        $record = $this->documents->find($document) ?? abort(404);

        return view('panel.collections.document', [
            'document' => $record,
            'html' => $this->documents->html($record),
            'editable' => DocumentService::EDITABLE,
            'placeholders' => DocumentTemplates::placeholders($record->kind),
            'canManage' => $request->user()->can('payment_allocation.manage'),
        ]);
    }

    public function updateDocument(Request $request, int $document): RedirectResponse
    {
        $record = $this->documents->find($document) ?? abort(404);
        $v = $request->validate(['customer_name' => ['required', 'string', 'max:160'], 'customer_tax_number' => ['nullable', 'string', 'max:20'], 'customer_email' => ['nullable', 'email', 'max:190'], 'description' => ['nullable', 'string', 'max:300'], 'invoice_description' => ['nullable', 'string', 'max:300'], 'note' => ['nullable', 'string', 'max:500'], 'date' => ['required', 'string', 'max:20']]);

        try {
            $this->documents->update($request->user(), $record, $v);
        } catch (DomainException $e) {
            return back()->withErrors(['customer_name' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.collections.documents.show', $record)->with('status', 'Belge güncellendi.');
    }

    public function cancelDocument(Request $request, int $document): RedirectResponse
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']])['reason'];
        $record = $this->documents->find($document) ?? abort(404);

        try {
            $this->documents->cancel($request->user(), $record, $reason);
        } catch (DomainException $e) {
            return back()->withErrors(['document' => $e->getMessage()]);
        }

        return redirect()->route('panel.collections.documents.show', $record)->with('status', 'Belge iptal edildi (kayıt geçmişte kalır).');
    }

    public function pdf(int $document): Response
    {
        $record = $this->documents->find($document) ?? abort(404);

        return response($this->documents->pdf($record), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$record->number.'.pdf"',
        ]);
    }

    /** Yazdırma görünümü: yalnız belge HTML'i + otomatik yazdırma. */
    public function print(int $document): View
    {
        $record = $this->documents->find($document) ?? abort(404);

        return view('panel.collections.print', ['document' => $record, 'html' => $this->documents->html($record)]);
    }

    // ---- Şablonlar ------------------------------------------------------------------

    public function template(Request $request, string $kind): View
    {
        if (! DocumentTemplates::exists($kind)) {
            abort(404);
        }

        // Kaydedilmemiş önizleme (?onizle=1 ile POST'tan dönen eski girdi) ya da kayıtlı şablon.
        $fields = $this->documents->template($kind);

        if ($request->old('heading') !== null) {
            $fields = array_replace($fields, $this->templateInput($request->old(), $kind));
        }

        $previewValues = $this->documents->sampleValues($kind);

        return view('panel.collections.template', [
            'kind' => $kind,
            'kinds' => DocumentTemplates::KINDS,
            'fieldDefs' => DocumentTemplates::FIELDS,
            'fields' => $fields,
            'columnOptions' => DocumentTemplates::columnOptions($kind),
            'placeholders' => DocumentTemplates::placeholders($kind),
            'sample' => $previewValues,
            'preview' => $this->documents->render($kind, $fields, $previewValues, false, true),
            'canEdit' => $request->user()->can('invoice.issue'),
        ]);
    }

    public function updateTemplate(Request $request, string $kind): RedirectResponse
    {
        if (! DocumentTemplates::exists($kind)) {
            abort(404);
        }

        $input = $this->templateInput($request->all(), $kind);

        // "Önizle": kaydetmeden, girdiyle yeniden çiz.
        if ($request->input('action') === 'preview') {
            return redirect()->route('panel.collections.templates.edit', $kind)->withInput($request->all());
        }

        try {
            $this->documents->updateTemplate($request->user(), $kind, $input);
        } catch (DomainException $e) {
            return back()->withErrors(['heading' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.collections.templates.edit', $kind)->with('status', DocumentTemplates::KINDS[$kind].' şablonu kaydedildi.');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function templateInput(array $input, string $kind): array
    {
        $out = [];

        foreach (DocumentTemplates::FIELDS as $key => $def) {
            $out[$key] = match ($def['type']) {
                'bool' => ! empty($input[$key]),
                'columns' => array_values(array_intersect(array_keys(DocumentTemplates::columnOptions($kind)), array_map('strval', (array) ($input[$key] ?? [])))),
                default => (string) ($input[$key] ?? ''),
            };
        }

        return $out;
    }
}
