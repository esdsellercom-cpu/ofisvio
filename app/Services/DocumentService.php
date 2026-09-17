<?php

namespace App\Services;

use App\Documents\DocumentTemplates;
use App\Models\Contract;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Support\Money;
use App\Support\NumberWords;
use DomainException;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Belge merkezi (faz 47): tahsilat makbuzu ve geciken ödeme belgesi.
 *
 * - Şablon: tür başına tek kayıt (document_templates); yoksa DocumentTemplates::defaults. {{yer_tutucu}} metinleri
 *   render anında değerlerle değişir.
 * - Belge: numara (MKB-YYYY-000001 / GOB-YYYY-000001, tür+yıl sırası satır kilidiyle), değerler `data`'ya dondurulur;
 *   düzenleme yalnız metin alanlarında (audit); silinmez, iptal edilir.
 * - Çıktı: aynı HTML görünümü önizleme, yazdırma ve PDF (dompdf) için kullanılır.
 *
 * withoutTenantScope: finans personeli (invoice.view / payment_allocation.manage, global) tüm şirketlerin
 * belgelerini görür; müşteri tarafı bu servisi kullanmaz (bkz. ArchitectureTest allowlist).
 */
class DocumentService
{
    /** Belge üzerinde düzenlenebilen alanlar (numara/tutar değişmez). */
    public const EDITABLE = ['customer_name', 'customer_tax_number', 'customer_email', 'description', 'invoice_description', 'note', 'date'];

    public function __construct(private readonly AuditService $audit, private readonly ContentService $contents, private readonly MembershipService $members) {}

    // ---- Şablon ------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function template(string $kind): array
    {
        $defaults = DocumentTemplates::defaults($kind);
        $stored = DocumentTemplate::query()->where('kind', $kind)->first();

        return array_replace($defaults, is_array($stored?->fields) ? $stored->fields : []);
    }

    /** @param  array<string, mixed>  $fields */
    public function updateTemplate(User $actor, string $kind, array $fields): DocumentTemplate
    {
        if (! DocumentTemplates::exists($kind)) {
            throw new DomainException('Tanımsız belge türü.');
        }

        $clean = [];

        foreach (DocumentTemplates::FIELDS as $key => $def) {
            $raw = $fields[$key] ?? null;
            $clean[$key] = match ($def['type']) {
                'bool' => (bool) $raw,
                'columns' => array_values(array_intersect(array_keys(DocumentTemplates::columnOptions($kind)), array_map('strval', (array) $raw))),
                'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $raw) === 1 ? strtolower((string) $raw) : '#1f5f4b',
                'url' => filter_var((string) $raw, FILTER_VALIDATE_URL) !== false && str_starts_with((string) $raw, 'https://') ? (string) $raw : (str_starts_with((string) $raw, '/') ? (string) $raw : ''),
                default => trim((string) $raw),
            };
        }

        if ($clean['heading'] === '') {
            throw new DomainException('Başlık boş olamaz.');
        }

        $template = DocumentTemplate::query()->firstOrNew(['kind' => $kind]);
        $before = $template->exists ? (array) $template->fields : [];
        $template->fill(['fields' => $clean, 'updated_by' => $actor->id])->save();
        $this->audit->record($actor, 'document.template_updated', 'document_template', $template->id, ['fields' => $before], ['fields' => $clean]);

        return $template;
    }

    // ---- Değerler ---------------------------------------------------------------

    /**
     * Makbuz değerleri (tahsilat anındaki fatura durumuyla).
     *
     * @return array<string, string>
     */
    public function receiptValues(Payment $payment, ?User $issuer = null): array
    {
        $invoice = $payment->invoice;
        $currency = (string) ($payment->currency ?: $invoice->currency);

        return $this->businessValues() + $this->customerValues($invoice) + [
            'invoice_number' => (string) ($invoice->number ?? '—'),
            'invoice_description' => (string) $invoice->description,
            'invoice_total' => Money::format($invoice->total, $currency),
            'due_date' => $invoice->due_on?->format('d.m.Y') ?? '—',
            'amount' => Money::format($payment->amount, $currency),
            'amount_words' => NumberWords::amount($payment->amount, $currency),
            'payment_method' => $payment->methodLabel().($payment->isCash() ? ' (nakit ödeme)' : ''),
            'payment_date' => $payment->paid_on->format('d.m.Y'),
            'payment_reference' => (string) ($payment->reference ?? '—'),
            'description' => (string) ($payment->description ?: $invoice->description),
            'note' => (string) ($payment->note ?? ''),
            'paid_amount' => Money::format($invoice->paid_amount, $currency),
            'remaining_amount' => Money::format($invoice->outstanding(), $currency),
            'currency' => $currency,
            'date' => Carbon::today()->format('d.m.Y'),
            'issuer_name' => $issuer !== null ? (string) $issuer->name : 'Sistem',
        ];
    }

    /** @return array<string, string> */
    public function overdueValues(Invoice $invoice, ?User $issuer = null): array
    {
        $currency = (string) $invoice->currency;

        return $this->businessValues() + $this->customerValues($invoice) + [
            'invoice_number' => (string) ($invoice->number ?? '—'),
            'invoice_description' => (string) $invoice->description,
            'invoice_total' => Money::format($invoice->total, $currency),
            'due_date' => $invoice->due_on?->format('d.m.Y') ?? '—',
            'days_overdue' => (string) max(0, $invoice->daysOverdue()),
            'amount' => Money::format($invoice->total, $currency),
            'paid_amount' => Money::format($invoice->paid_amount, $currency),
            'remaining_amount' => Money::format($invoice->outstanding(), $currency),
            'note' => (string) ($invoice->note ?? ''),
            'currency' => $currency,
            'date' => Carbon::today()->format('d.m.Y'),
            'issuer_name' => $issuer !== null ? (string) $issuer->name : 'Sistem',
        ];
    }

    /**
     * Şablon düzenleme önizlemesi: gerçek son kayıt varsa onun değerleri, yoksa etiketli yer tutucular.
     *
     * @return array<string, string>
     */
    public function sampleValues(string $kind): array
    {
        if ($kind === 'receipt') {
            $payment = Payment::withoutTenantScope()->where('status', 'recorded')->with(['invoice.company' => fn ($c) => $c->withoutGlobalScope(TenantScope::class)])->latest('id')->first();

            if ($payment !== null && $payment->invoice !== null) {
                return $this->receiptValues($payment) + ['document_number' => Document::PREFIX['receipt'].'-'.Carbon::today()->format('Y').'-000001'];
            }
        } else {
            $invoice = Invoice::withoutTenantScope()->where('status', 'overdue')->with(['company' => fn ($c) => $c->withoutGlobalScope(TenantScope::class)])->orderBy('due_on')->first();

            if ($invoice !== null) {
                return $this->overdueValues($invoice) + ['document_number' => Document::PREFIX['overdue_notice'].'-'.Carbon::today()->format('Y').'-000001'];
            }
        }

        $values = [];

        foreach (DocumentTemplates::placeholders($kind) as $key => $label) {
            $values[$key] = '«'.$label.'»';
        }

        return $this->businessValues() + $values + ['date' => Carbon::today()->format('d.m.Y')];
    }

    // ---- Belgeler ---------------------------------------------------------------

    /** Makbuz: tahsilat başına tek geçerli makbuz; varsa o döner. */
    public function createReceipt(User $actor, Payment $payment): Document
    {
        if ($payment->isCancelled()) {
            throw new DomainException('İptal edilmiş tahsilata makbuz düzenlenmez.');
        }

        $existing = Document::query()->where('payment_id', $payment->id)->where('kind', 'receipt')->where('status', 'issued')->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->create($actor, 'receipt', $payment->company_id, $payment->invoice_id, $payment->id, $this->receiptValues($payment, $actor));
    }

    /** Sözleşme belgesi (faz 51): sözleşme kaydından, belge motoruyla (şablon, PDF, yazdırma). */
    public function createContract(User $actor, Contract $contract): Document
    {
        $company = $contract->company;
        $member = $contract->membership;
        $values = $this->businessValues() + [
            'customer_name' => $company->legal_name, 'customer_tax_number' => (string) ($company->tax_number ?? ''), 'customer_email' => (string) ($this->members->primaryContact($company->id)->email ?? ''),
            'invoice_number' => '', 'invoice_description' => '', 'invoice_total' => '', 'due_date' => '',
            'contract_number' => $contract->number, 'contract_type' => $contract->typeLabel(), 'contract_start' => $contract->starts_on->format('d.m.Y'), 'contract_end' => $contract->ends_on?->format('d.m.Y') ?? 'Süresiz',
            'member_name' => (string) ($member->user->name ?? ''), 'member_no' => (string) ($member->profile->member_no ?? ''), 'note' => (string) ($contract->note ?? ''),
            'date' => Carbon::today()->format('d.m.Y'), 'currency' => '', 'issuer_name' => $actor->name,
        ];

        $document = $this->create($actor, 'contract', $contract->company_id, null, null, $values);
        $document->forceFill(['contract_id' => $contract->id])->save();

        return $document;
    }

    public function createOverdueNotice(User $actor, Invoice $invoice): Document
    {
        if (! $invoice->isOpen() || $invoice->daysOverdue() < 0) {
            throw new DomainException('Geciken ödeme belgesi yalnız vadesi geçmiş açık fatura için düzenlenir.');
        }

        return $this->create($actor, 'overdue_notice', $invoice->company_id, $invoice->id, null, $this->overdueValues($invoice, $actor));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Document $document, array $data): Document
    {
        if ($document->isCancelled()) {
            throw new DomainException('İptal edilmiş belge düzenlenmez.');
        }

        $values = (array) $document->data;
        $before = array_intersect_key($values, array_flip(self::EDITABLE));

        foreach (self::EDITABLE as $key) {
            if (array_key_exists($key, $data)) {
                $values[$key] = trim((string) $data[$key]);
            }
        }

        if (($values['customer_name'] ?? '') === '') {
            throw new DomainException('Müşteri adı boş olamaz.');
        }

        $document->fill(['data' => $values])->save();
        $this->audit->record($actor, 'document.updated', 'document', $document->id, $before, array_intersect_key($values, array_flip(self::EDITABLE)));

        return $document;
    }

    public function cancel(User $actor, Document $document, string $reason): Document
    {
        if ($document->isCancelled()) {
            throw new DomainException('Belge zaten iptal.');
        }

        $before = $document->only(['status']);
        $document->fill(['status' => 'cancelled', 'cancelled_by' => $actor->id, 'cancelled_at' => Carbon::now(), 'cancel_reason' => $reason])->save();
        $this->audit->record($actor, 'document.cancelled', 'document', $document->id, $before, $document->only(['status', 'cancel_reason']));

        return $document;
    }

    public function find(int $id): ?Document
    {
        return Document::query()->with(['company' => fn ($c) => $c->withoutGlobalScope(TenantScope::class), 'invoice', 'payment', 'creator'])->find($id);
    }

    /**
     * @param  array{kind?: string|null, q?: string|null}  $filters
     * @return Collection<int, Document>
     */
    public function all(array $filters = [], int $limit = 100): Collection
    {
        return Document::query()->with(['company' => fn ($c) => $c->withoutGlobalScope(TenantScope::class), 'invoice', 'creator'])
            ->when(! empty($filters['kind']), fn (Builder $q) => $q->where('kind', (string) $filters['kind']))
            ->when(! empty($filters['q']), fn (Builder $q) => $q->where('number', 'like', '%'.$filters['q'].'%'))
            ->latest('id')->limit($limit)->get();
    }

    // ---- Çıktı ------------------------------------------------------------------

    /**
     * Belge HTML'i (önizleme / yazdırma / PDF aynı görünüm).
     *
     * @param  array<string, mixed>|null  $template  null = kayıtlı şablon
     */
    public function html(Document $document, ?array $template = null): string
    {
        return $this->render($document->kind, $template ?? $this->template($document->kind), ((array) $document->data) + ['document_number' => $document->number], $document->isCancelled());
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, string>  $values
     */
    public function render(string $kind, array $template, array $values, bool $cancelled = false, bool $live = false): string
    {
        $sub = fn (mixed $text): string => $this->substitute((string) $text, $values);
        $columns = [];

        foreach ((array) ($template['columns'] ?? []) as $column) {
            $columns[(string) $column] = DocumentTemplates::columnOptions($kind)[$column] ?? (string) $column;
        }

        return view('documents.render', [
            'kind' => $kind,
            'template' => $template,
            'values' => $values,
            'columns' => $columns,
            'sub' => $sub,
            'cancelled' => $cancelled,
            'live' => $live, // şablon düzenleyici: boş bölümler de (gizli) basılır ki JS canlı doldurabilsin
        ])->render();
    }

    public function pdf(Document $document): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false); // dış görsel indirilmez (SSRF yok); logo yalnız /public altından
        $options->set('chroot', public_path());
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($document), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** {{key}} → değer; tanımsız yer tutucu olduğu gibi kalır (yazım hatası görünür olsun). */
    public function substitute(string $text, array $values): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn (array $m) => array_key_exists($m[1], $values) ? (string) $values[$m[1]] : $m[0], $text);
    }

    // ---- Yardımcılar -------------------------------------------------------------

    /** @param  array<string, string>  $values */
    private function create(User $actor, string $kind, int $companyId, ?int $invoiceId, ?int $paymentId, array $values): Document
    {
        return DB::transaction(function () use ($actor, $kind, $companyId, $invoiceId, $paymentId, $values) {
            $year = (int) Carbon::today()->format('Y');
            $number = sprintf('%s-%d-%06d', Document::PREFIX[$kind], $year, $this->nextSequence($kind, $year));
            $document = new Document([
                'kind' => $kind, 'number' => $number, 'company_id' => $companyId, 'invoice_id' => $invoiceId, 'payment_id' => $paymentId,
                'data' => $values + ['document_number' => $number], 'status' => 'issued', 'created_by' => $actor->id,
            ]);
            $document->save();
            $this->audit->record($actor, 'document.created', 'document', $document->id, [], ['kind' => $kind, 'number' => $number, 'invoice_id' => $invoiceId, 'payment_id' => $paymentId]);

            return $document;
        });
    }

    private function nextSequence(string $kind, int $year): int
    {
        $row = DB::table('document_sequences')->where('kind', $kind)->where('year', $year)->lockForUpdate()->first();

        if ($row === null) {
            try {
                DB::table('document_sequences')->insert(['kind' => $kind, 'year' => $year, 'last' => 0, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
            } catch (QueryException) {
                // Başka bir işlem aynı anda ekledi; kilitli okuma tekrar.
            }

            $row = DB::table('document_sequences')->where('kind', $kind)->where('year', $year)->lockForUpdate()->first();
        }

        $next = (int) $row->last + 1;
        DB::table('document_sequences')->where('kind', $kind)->where('year', $year)->update(['last' => $next, 'updated_at' => Carbon::now()]);

        return $next;
    }

    /** @return array<string, string> */
    private function businessValues(): array
    {
        $site = $this->contents->defaultWebsiteOrNull();
        $brand = $site?->brand();

        return [
            'business_name' => (string) ($brand['name'] ?? ''),
            'business_legal_name' => (string) ($brand['legal_name'] ?? ($brand['name'] ?? '')),
            'business_address' => (string) ($brand['address'] ?? ''),
            'business_phone' => (string) ($brand['phone'] ?? ''),
            'business_email' => (string) ($brand['email'] ?? ''),
        ];
    }

    /** @return array<string, string> */
    private function customerValues(Invoice $invoice): array
    {
        $company = $invoice->company;
        $contact = $company !== null ? $this->members->primaryContact($company->id) : null;

        return [
            'customer_name' => $company !== null ? (string) $company->legal_name : '—',
            'customer_tax_number' => $company !== null ? (string) ($company->tax_number ?? '') : '',
            'customer_email' => $contact !== null ? (string) $contact->email : '',
        ];
    }
}
