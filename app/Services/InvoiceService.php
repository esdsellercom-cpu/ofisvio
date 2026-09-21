<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Scopes\TenantScope;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Money;
use App\Webhooks\WebhookDispatcher;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ödemeler & faturalandırma + tahsilat takibi (faz 39c, artifact §7–8).
 *
 * Fatura durumu yalnız burada değişir: draft → issued (numara verilir) → paid ·
 * issued → overdue (vade + tolerans geçti, zamanlayıcı) → paid · draft/issued/overdue
 * → cancelled. Tahsilat kaydı faturanın paid_amount'unu günceller; tutar tamamlanınca
 * paid. Ödeme sağlayıcısı (iyzico) geçitten bağlanınca webhook aynı recordPayment'i
 * çağırır — manuel kayıt akışı değişmez. Her adım audit.
 *
 * withoutTenantScope: finans listesi/sayaçlar/dashboard (invoice.view GLOBAL rotası),
 * zamanlayıcının gecikme işareti ve numara sırası tüm şirketlere bakmak zorunda;
 * müşteri tarafı forCompany() tenant scope içinde kalır (ArchitectureTest allowlist).
 */
class InvoiceService
{
    /** Referanssız çift kayıt penceresi (saniye): aynı tutar/yöntem/tarih/kayıt eden → çift tık (audit F-01). */
    public const DUPLICATE_WINDOW_SECONDS = 60;

    public const TABS = ['open' => 'Tahsilat bekleyen', 'overdue' => 'Gecikmiş', 'paid' => 'Ödenen', 'draft' => 'Taslak', 'cancelled' => 'İptal', 'all' => 'Tümü'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly SettingsService $settings,
        private readonly NotificationService $notifications,
        private readonly MembershipService $members,
        private readonly CompanyActivationService $activation,
        private readonly BookingService $bookings,
        private readonly WebhookDispatcher $webhooks,
    ) {}

    /**
     * @param  array{tab?: string|null, q?: string|null}  $filters
     * @return LengthAwarePaginator<int, Invoice>
     */
    public function paginateAll(array $filters, int $perPage = 50): LengthAwarePaginator
    {
        $tab = (string) ($filters['tab'] ?? 'open');
        $q = trim((string) ($filters['q'] ?? ''));

        $query = Invoice::withoutTenantScope()
            ->when($q !== '', fn (Builder $b) => $b->where(fn (Builder $w) => $w->where('number', 'like', "%{$q}%")->orWhere('description', 'like', "%{$q}%")
                ->orWhereHas('company', fn (Builder $c) => $c->withoutGlobalScope(TenantScope::class)->where('legal_name', 'like', "%{$q}%"))));

        $this->applyTab($query, $tab);

        return $query
            ->with(['company' => fn ($c) => $c->withoutGlobalScope(TenantScope::class), 'subscription.plan'])
            ->orderBy(in_array($tab, ['open', 'overdue'], true) ? 'due_on' : 'updated_at', in_array($tab, ['open', 'overdue'], true) ? 'asc' : 'desc')
            ->paginate($perPage)->withQueryString();
    }

    /** @return array<string, int> */
    public function tabCounts(): array
    {
        $out = [];

        foreach (array_keys(self::TABS) as $tab) {
            if ($tab !== 'all') {
                $out[$tab] = $this->applyTab(Invoice::withoutTenantScope(), $tab)->count();
            }
        }

        return $out;
    }

    /**
     * Menü rozeti: gecikmiş fatura (PanelBadgeService tek sorguda sayar).
     *
     * @return Builder<Invoice>
     */
    public function overdueQuery(): Builder
    {
        return Invoice::withoutTenantScope()->where('status', 'overdue');
    }

    public function findAny(int $id): ?Invoice
    {
        return Invoice::withoutTenantScope()->with(['company' => fn ($c) => $c->withoutGlobalScope(TenantScope::class), 'subscription.plan', 'booking', 'creator', 'payments.recorder'])->find($id);
    }

    /**
     * Müşteri tarafı (tenant scope içinde): taslaklar müşteriye görünmez.
     *
     * @return Collection<int, Invoice>
     */
    public function forCompany(Company $company): Collection
    {
        return Invoice::query()->where('company_id', $company->id)->where('status', '!=', 'draft')->with('payments')->orderByDesc('issued_on')->orderByDesc('id')->get();
    }

    public function findForCompany(Company $company, int $id): ?Invoice
    {
        return Invoice::query()->where('company_id', $company->id)->where('status', '!=', 'draft')->with(['payments', 'subscription.plan'])->find($id);
    }

    /**
     * Taslak oluşturur (invoice.issue). Tutar: ara toplam + KDV. Vade yayınlamada kesinleşir.
     *
     * @param  array{description: string, subtotal: int|string, tax_rate?: int|null, due_on?: string|null, subscription_id?: int|string|null, note?: string|null, issue?: bool}  $data  subtotal büyük birim (₺ ondalıklı); kuruşa çevrilir
     */
    public function create(User $actor, Company $company, array $data): Invoice
    {
        $subtotal = max(0, Money::parse((string) $data['subtotal']));
        $rate = max(0, min(100, (int) ($data['tax_rate'] ?? $this->settings->int('finance.default_tax_rate'))));
        $tax = Money::percent($subtotal, $rate);
        $subscription = ! empty($data['subscription_id']) ? Subscription::withoutTenantScope()->where('company_id', $company->id)->find((int) $data['subscription_id']) : null;

        if (! empty($data['subscription_id']) && $subscription === null) {
            throw new DomainException('Üyelik bu şirkete ait değil.');
        }

        $invoice = new Invoice([
            'company_id' => $company->id,
            'subscription_id' => $subscription?->id,
            'status' => 'draft',
            'description' => trim((string) $data['description']),
            'subtotal' => $subtotal,
            'tax_rate' => $rate,
            'tax_amount' => $tax,
            'total' => $subtotal + $tax,
            'currency' => $this->settings->string('general.currency'),
            'due_on' => ! empty($data['due_on']) ? Carbon::parse($data['due_on'])->toDateString() : null,
            'note' => $this->blank($data['note'] ?? null),
            'created_by' => $actor->id,
        ]);
        $invoice->save();
        $this->audit->record($actor, 'invoice.created', 'invoice', $invoice->id, [], $invoice->toArray());

        if (! empty($data['issue'])) {
            $this->issue($actor, $invoice);
        }

        return $invoice;
    }

    /**
     * Sistem faturası (üyelik yenileme, audit P1-5): aktör yok, tutar kuruş, ayardaki KDV/vade, doğrudan yayın.
     *
     * @param  array{description: string, subtotal_minor: int, subscription_id?: int|null}  $data
     */
    public function createSystem(Company $company, array $data, ?User $actor = null): Invoice
    {
        $subtotal = max(0, (int) $data['subtotal_minor']);
        $rate = $this->settings->int('finance.default_tax_rate');
        $tax = Money::percent($subtotal, $rate);

        $invoice = new Invoice([
            'company_id' => $company->id,
            'subscription_id' => $data['subscription_id'] ?? null,
            'status' => 'draft',
            'description' => trim($data['description']),
            'subtotal' => $subtotal,
            'tax_rate' => $rate,
            'tax_amount' => $tax,
            'total' => $subtotal + $tax,
            'currency' => $this->settings->string('general.currency'),
            'created_by' => $actor?->id,
        ]);
        $invoice->save();
        $this->audit->record($actor, 'invoice.created', 'invoice', $invoice->id, [], $invoice->toArray());

        return $this->issue($actor, $invoice);
    }

    /**
     * Rezervasyon faturası (audit P1-12 / H-6): onaylı, şirkete bağlı, tutarı olan rezervasyon için tek fatura;
     * ayar kapalıysa, şirket yoksa ya da fatura zaten varsa null.
     */
    public function createForBooking(Booking $booking): ?Invoice
    {
        if (! $this->settings->bool('finance.auto_invoice_bookings') || $booking->company_id === null || $booking->total_amount <= 0) {
            return null;
        }

        if (Invoice::withoutTenantScope()->where('booking_id', $booking->id)->where('status', '!=', 'cancelled')->exists()) {
            return null;
        }

        $company = Company::withoutTenantScope()->find($booking->company_id);

        if ($company === null) {
            return null;
        }

        $invoice = $this->createSystem($company, [
            'description' => 'Rezervasyon '.$booking->reference.' · '.($booking->room->name ?? 'Oda').' · '.$booking->starts_at->format('d.m.Y H:i').'–'.$booking->ends_at->format('H:i'),
            'subtotal_minor' => $booking->total_amount,
        ]);
        $invoice->forceFill(['booking_id' => $booking->id])->save();

        return $invoice;
    }

    /** Rezervasyon iptal/red/süre dolumu: ödemesi olmayan açık fatura sistemce iptal edilir; tahsilatlı fatura finansa kalır. */
    public function cancelForBooking(Booking $booking, string $reason): ?Invoice
    {
        $invoice = Invoice::withoutTenantScope()->where('booking_id', $booking->id)->whereIn('status', Invoice::OPEN)->where('paid_amount', 0)->first();

        return $invoice === null ? null : $this->cancel(null, $invoice, $reason);
    }

    /** Rezervasyon faturası ise ödeme durumu rezervasyona yansır (faz 45; BookingService yazar). */
    private function syncBookingPayment(Invoice $invoice, string $status): void
    {
        if ($invoice->booking_id === null) {
            return;
        }

        $booking = Booking::withoutTenantScope()->find($invoice->booking_id);

        if ($booking !== null) {
            $this->bookings->syncPaymentStatus($booking, $status, $invoice->paid_at);
        }
    }

    /** Yayınla: numara (önek-yıl-sıra) + yayın tarihi + vade (yoksa ayardan). Sıfır tutarlı fatura yayınlanmaz. */
    public function issue(?User $actor, Invoice $invoice): Invoice
    {
        if ($invoice->status !== 'draft') {
            throw new DomainException('Yalnız taslak fatura yayınlanır.');
        }

        if ($invoice->total <= 0) {
            throw new DomainException('Sıfır tutarlı fatura yayınlanamaz.');
        }

        return DB::transaction(function () use ($actor, $invoice) {
            $before = $invoice->toArray();
            $today = Carbon::today();
            $year = $today->format('Y');
            $prefix = $this->settings->string('finance.invoice_prefix');
            $seq = $this->nextSequence((int) $year);

            $invoice->fill([
                'number' => sprintf('%s-%s-%06d', $prefix, $year, $seq),
                'status' => 'issued',
                'issued_on' => $today->toDateString(),
                'due_on' => $invoice->due_on?->toDateString() ?? $today->copy()->addDays($this->settings->int('finance.due_days'))->toDateString(),
                'issued_by' => $actor?->id,
            ])->save();
            $this->audit->record($actor, 'invoice.issued', 'invoice', $invoice->id, $before, $invoice->toArray());
            DB::afterCommit(fn () => $this->notify('invoice.issued', $invoice));

            return $invoice;
        });
    }

    /**
     * Yıl başına atomik sayaç (audit H-2): invoice_sequences satırı kilitlenir, artırılır.
     * İlk fatura için satır yoksa eklenir; eşzamanlı ilk ekleme birincil anahtarla çakışırsa
     * yeniden okunur. İptal edilen fatura numarasını korur; sıra geri alınmaz (denetim izi).
     */
    private function nextSequence(int $year): int
    {
        $row = DB::table('invoice_sequences')->where('year', $year)->lockForUpdate()->first();

        if ($row === null) {
            try {
                DB::table('invoice_sequences')->insert(['year' => $year, 'last' => 0, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
            } catch (QueryException) {
                // Başka bir işlem aynı anda ekledi; kilitli okuma tekrar.
            }

            $row = DB::table('invoice_sequences')->where('year', $year)->lockForUpdate()->first();
        }

        $next = (int) $row->last + 1;
        DB::table('invoice_sequences')->where('year', $year)->update(['last' => $next, 'updated_at' => Carbon::now()]);

        return $next;
    }

    public function cancel(?User $actor, Invoice $invoice, string $reason): Invoice
    {
        if ($invoice->status === 'paid' || $invoice->status === 'cancelled') {
            throw new DomainException('Ödenmiş ya da iptal edilmiş fatura iptal edilemez.');
        }

        if ($invoice->paid_amount > 0) {
            throw new DomainException('Kısmi tahsilatı olan fatura iptal edilemez; önce tahsilatı iade/düzeltme kaydıyla kapatın.');
        }

        $before = $invoice->toArray();
        $invoice->fill(['status' => 'cancelled', 'cancelled_by' => $actor?->id, 'cancelled_at' => Carbon::now(), 'cancel_reason' => $reason])->save();
        $this->audit->record($actor, 'invoice.cancelled', 'invoice', $invoice->id, $before, $invoice->toArray());
        $this->syncBookingPayment($invoice, 'unpaid');

        return $invoice;
    }

    /**
     * Tahsilat kaydı (payment_allocation.manage ya da sağlayıcı webhook'u). Fazla ödeme reddedilir;
     * bakiye sıfırlanınca fatura paid.
     *
     * Bütünlük (audit F-01): bakiye ve durum kontrolü İŞLEM İÇİNDE, `lockForUpdate` ile yeniden okunan fatura
     * satırında yapılır — eşzamanlı iki kayıt (çift tık, iki personel, webhook tekrarı) sırayla çalışır ve ikincisi
     * bakiyeyi aşamaz. Idempotency: aynı faturada aynı `reference` ile iptal edilmemiş tahsilat varsa reddedilir;
     * referanssız kayıtlarda aynı tutar+yöntem+tarih+kayıt eden ile DUPLICATE_WINDOW_SECONDS içinde ikinci kayıt
     * çift tık sayılır. Dışarıdan verilen model örneği işlem sonunda kilitli satırla eşitlenir.
     *
     * @param  array{amount: int|string, method: string, paid_on: string, reference?: string|null, note?: string|null, description?: string|null, currency?: string|null}  $data  amount büyük birim (₺); webhook kuruş gönderiyorsa önce Money::major ile geçirilir
     */
    public function recordPayment(?User $actor, Invoice $invoice, array $data): Payment
    {
        if (! $invoice->isOpen()) {
            throw new DomainException('Yalnız yayınlanmış (tahsilat bekleyen) faturaya ödeme kaydedilir.');
        }

        $amount = Money::parse((string) $data['amount']);

        if ($amount <= 0 || $amount > $invoice->outstanding()) {
            throw new DomainException('Tutar 0,01 ile kalan bakiye ('.Money::format($invoice->outstanding()).') arasında olmalı.');
        }

        if (! isset(Payment::METHODS[(string) $data['method']])) {
            throw new DomainException('Geçersiz ödeme yöntemi.');
        }

        if (! empty($data['currency']) && strtoupper((string) $data['currency']) !== strtoupper((string) $invoice->currency)) {
            throw new DomainException('Para birimi faturanınkiyle aynı olmalı ('.$invoice->currency.').');
        }

        if (Carbon::parse((string) $data['paid_on'])->gt(Carbon::today())) {
            throw new DomainException('Ödeme tarihi ileri bir tarih olamaz.');
        }

        $payment = DB::transaction(function () use ($actor, $invoice, $data, $amount) {
            /** @var Invoice $locked */
            $locked = Invoice::withoutTenantScope()->lockForUpdate()->findOrFail($invoice->id); // kilitli, güncel satır
            $invoice->setRawAttributes($locked->getAttributes(), true); // çağıranın örneği kilitli satırla eşit (ret durumunda da güncel)

            if (! $locked->isOpen()) {
                throw new DomainException('Yalnız yayınlanmış (tahsilat bekleyen) faturaya ödeme kaydedilir.');
            }

            if ($amount > $locked->outstanding()) {
                throw new DomainException('Tutar kalan bakiyeyi ('.Money::format($locked->outstanding()).') aşamaz; bu arada başka bir tahsilat kaydedilmiş olabilir.');
            }

            $reference = $this->blank($data['reference'] ?? null);
            $duplicate = Payment::withoutTenantScope()->where('invoice_id', $locked->id)->where('status', '!=', 'cancelled')
                ->when($reference !== null, fn (Builder $q) => $q->where('reference', $reference))
                ->when($reference === null, fn (Builder $q) => $q->whereNull('reference')->where('amount', $amount)->where('method', (string) $data['method'])
                    ->whereDate('paid_on', Carbon::parse((string) $data['paid_on'])->toDateString())
                    ->where('recorded_by', $actor?->id)->where('created_at', '>=', Carbon::now()->subSeconds(self::DUPLICATE_WINDOW_SECONDS)))
                ->exists();

            if ($duplicate) {
                throw new DomainException($reference !== null
                    ? 'Bu referansla ('.$reference.') bu faturada zaten tahsilat kayıtlı; aynı ödeme ikinci kez kaydedilmez.'
                    : 'Aynı tutar ve yöntemle az önce bir tahsilat kaydedildi; çift kayıt engellendi. Gerçekten ikinci bir ödemeyse referans girin.');
            }

            $payment = new Payment([
                'invoice_id' => $invoice->id,
                'company_id' => $invoice->company_id,
                'amount' => $amount,
                'currency' => (string) $invoice->currency,
                'method' => (string) $data['method'],
                'description' => $this->blank($data['description'] ?? null) ?? $this->blank($invoice->description),
                'paid_on' => Carbon::parse($data['paid_on'])->toDateString(),
                'reference' => $this->blank($data['reference'] ?? null),
                'note' => $this->blank($data['note'] ?? null),
                'recorded_by' => $actor?->id,
                'status' => 'recorded',
            ]);
            $payment->save();

            $before = $invoice->toArray();
            $paid = $invoice->paid_amount + $amount;
            $invoice->fill(['paid_amount' => $paid] + ($paid >= $invoice->total ? ['status' => 'paid', 'paid_at' => Carbon::now()] : []))->save();

            $this->audit->record($actor, 'payment.recorded', 'payment', $payment->id, [], $payment->toArray());
            $this->audit->record($actor, $invoice->status === 'paid' ? 'invoice.paid' : 'invoice.partially_paid', 'invoice', $invoice->id, $before, $invoice->toArray());
            $this->syncBookingPayment($invoice, $invoice->status === 'paid' ? 'paid' : 'partial');
            $this->webhooks->emit('payment.received', ['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'invoice_number' => $invoice->number, 'company_id' => $invoice->company_id, 'amount' => $amount, 'currency' => (string) $invoice->currency, 'method' => (string) $data['method'], 'paid_on' => (string) $data['paid_on'], 'invoice_status' => $invoice->status, 'outstanding' => $invoice->outstanding()]);

            if ($invoice->status === 'paid') {
                DB::afterCommit(fn () => $this->notify('invoice.paid', $invoice));
            }

            return $payment;
        });

        return $payment;
    }

    /**
     * Tahsilat iptali (faz 47): kayıt silinmez, 'cancelled' olur; fatura bakiyesi geri alınır (ödenmişse yeniden
     * açılır: vade geçmişse overdue, değilse issued), makbuzu varsa iptal edilir; audit + rezervasyon ödeme durumu.
     */
    public function cancelPayment(User $actor, Payment $payment, string $reason): Payment
    {
        if ($payment->isCancelled()) {
            throw new DomainException('Tahsilat zaten iptal edilmiş.');
        }

        return DB::transaction(function () use ($actor, $payment, $reason) {
            $invoice = Invoice::withoutTenantScope()->lockForUpdate()->findOrFail($payment->invoice_id);
            $before = $payment->only(['status']);
            $payment->fill(['status' => 'cancelled', 'cancelled_by' => $actor->id, 'cancelled_at' => Carbon::now(), 'cancel_reason' => $reason])->save();

            $invoiceBefore = $invoice->toArray();
            $paid = max(0, $invoice->paid_amount - $payment->amount);
            $status = $invoice->status;

            if ($invoice->status === 'paid' && $paid < $invoice->total) {
                $status = $invoice->due_on !== null && $invoice->due_on->lt(Carbon::today()) ? 'overdue' : 'issued';
            }

            $invoice->fill(['paid_amount' => $paid, 'status' => $status, 'paid_at' => $status === 'paid' ? $invoice->paid_at : null])->save();

            foreach (Document::query()->where('payment_id', $payment->id)->where('status', 'issued')->get() as $document) {
                $document->fill(['status' => 'cancelled', 'cancelled_by' => $actor->id, 'cancelled_at' => Carbon::now(), 'cancel_reason' => 'Tahsilat iptal edildi: '.$reason])->save();
                $this->audit->record($actor, 'document.cancelled', 'document', $document->id, ['status' => 'issued'], ['status' => 'cancelled', 'cancel_reason' => $reason]);
            }

            $this->audit->record($actor, 'payment.cancelled', 'payment', $payment->id, $before, $payment->only(['status', 'cancel_reason']));
            $this->audit->record($actor, 'invoice.payment_reversed', 'invoice', $invoice->id, $invoiceBefore, $invoice->toArray());
            $this->syncBookingPayment($invoice, $paid <= 0 ? 'unpaid' : ($status === 'paid' ? 'paid' : 'partial'));

            return $payment;
        });
    }

    public function findPayment(int $id): ?Payment
    {
        return Payment::withoutTenantScope()->with(['invoice', 'company' => fn ($c) => $c->withoutGlobalScope(TenantScope::class), 'recorder', 'receipt'])->find($id);
    }

    /**
     * Tahsilat listesi (faz 47): son kayıtlar, iptaller dahil (geçmiş görünür).
     *
     * @param  array{method?: string|null, q?: string|null, status?: string|null}  $filters
     * @return Collection<int, Payment>
     */
    public function payments(array $filters = [], int $limit = 100): Collection
    {
        return Payment::withoutTenantScope()->with(['invoice', 'company' => fn ($c) => $c->withoutGlobalScope(TenantScope::class), 'recorder', 'receipt'])
            ->when(! empty($filters['method']), fn (Builder $q) => $q->where('method', (string) $filters['method']))
            ->when(! empty($filters['status']), fn (Builder $q) => $q->where('status', (string) $filters['status']))
            ->when(! empty($filters['q']), fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('reference', 'like', '%'.$filters['q'].'%')->orWhere('description', 'like', '%'.$filters['q'].'%')->orWhereHas('invoice', fn (Builder $i) => $i->where('number', 'like', '%'.$filters['q'].'%'))))
            ->orderByDesc('paid_on')->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * Gecikmiş faturalar (faz 47 — Geciken ödemeler sekmesi): vadesi geçmiş açık faturalar, en eski önce.
     *
     * @return Collection<int, Invoice>
     */
    public function overdueList(): Collection
    {
        return Invoice::withoutTenantScope()->whereIn('status', Invoice::OPEN)->whereNotNull('due_on')->whereDate('due_on', '<', Carbon::today()->toDateString())
            ->with(['company' => fn ($c) => $c->withoutGlobalScope(TenantScope::class)])->orderBy('due_on')->get();
    }

    /**
     * Manuel tahsilat formu: tahsilat bekleyen faturalar (şirketle).
     *
     * @return Collection<int, Invoice>
     */
    public function openForPayment(): Collection
    {
        return Invoice::withoutTenantScope()->whereIn('status', Invoice::OPEN)->with(['company' => fn ($c) => $c->withoutGlobalScope(TenantScope::class)])->orderBy('company_id')->orderBy('due_on')->get();
    }

    /** Zamanlayıcı: vade + tolerans geçen yayınlanmış faturalar overdue. */
    public function markOverdue(): int
    {
        $limit = Carbon::today()->subDays($this->settings->int('finance.overdue_grace_days'))->toDateString();
        $n = 0;

        foreach (Invoice::withoutTenantScope()->where('status', 'issued')->whereDate('due_on', '<', $limit)->get() as $invoice) {
            $before = $invoice->toArray();
            $invoice->fill(['status' => 'overdue'])->save();
            $this->audit->record(null, 'invoice.overdue', 'invoice', $invoice->id, $before, $invoice->toArray());
            $this->notify('invoice.overdue', $invoice);
            $this->webhooks->emit('invoice.overdue', ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->number, 'company_id' => $invoice->company_id, 'total' => $invoice->total, 'outstanding' => $invoice->outstanding(), 'currency' => (string) $invoice->currency, 'due_on' => $invoice->due_on?->toDateString()]);
            $n++;
        }

        return $n;
    }

    /**
     * Zamanlayıcı (audit P1-7): vadeye N gün kala tek seferlik hatırlatma (finance.reminder_days_before; 0 = kapalı).
     */
    public function remindDueSoon(): int
    {
        $days = $this->settings->int('finance.reminder_days_before');

        if ($days <= 0) {
            return 0;
        }

        $n = 0;
        $limit = Carbon::today()->addDays($days)->toDateString();

        foreach (Invoice::withoutTenantScope()->where('status', 'issued')->whereNull('due_reminder_sent_at')->whereDate('due_on', '<=', $limit)->whereDate('due_on', '>=', Carbon::today()->toDateString())->get() as $invoice) {
            $invoice->fill(['due_reminder_sent_at' => Carbon::now()])->save();
            $this->notify('invoice.due_soon', $invoice);
            $n++;
        }

        return $n;
    }

    /**
     * Zamanlayıcı (audit P1-7): vadesi N günden fazla geçmiş açık faturası olan AKTİF şirket askıya alınır
     * (finance.suspend_after_overdue_days; 0 = kapalı). Durum yalnız CompanyActivationService yazar; audit + bildirim.
     */
    public function suspendLongOverdue(): int
    {
        $days = $this->settings->int('finance.suspend_after_overdue_days');

        if ($days <= 0) {
            return 0;
        }

        $limit = Carbon::today()->subDays($days)->toDateString();
        $n = 0;
        $companyIds = Invoice::withoutTenantScope()->where('status', 'overdue')->whereDate('due_on', '<', $limit)->distinct()->pluck('company_id');

        foreach (Company::withoutTenantScope()->whereIn('id', $companyIds)->where('status', CompanyStatus::ACTIVE->value)->get() as $company) {
            $reason = "Vadesi {$days} günden fazla geçmiş açık fatura (otomatik)";
            $this->activation->transitionTo($company, CompanyStatus::SUSPENDED, null, $reason);
            $this->audit->record(null, 'company.suspended_overdue', 'company', $company->id, ['status' => CompanyStatus::ACTIVE->value], ['status' => CompanyStatus::SUSPENDED->value, 'reason' => $reason], $company->organization_id);
            $contact = $this->members->primaryContact($company->id);
            $this->notifications->dispatch('company.suspended', [
                'company_name' => $company->legal_name,
                'customer_name' => $contact?->name,
                'customer_email' => $contact?->email,
                'customer_user_id' => $contact?->id,
                'reason' => $reason,
            ], null, 'company', $company->id);
            $n++;
        }

        return $n;
    }

    /** Fatura olayını müşteri muhatabı + finans grubuna gönderir (kurallar Bildirim Merkezi'nden). */
    private function notify(string $event, Invoice $invoice): void
    {
        $company = Company::withoutTenantScope()->find($invoice->company_id);
        $contact = $this->members->primaryContact($invoice->company_id);

        $this->notifications->dispatch($event, [
            'number' => (string) $invoice->number,
            'company_name' => (string) ($company->legal_name ?? ''),
            'customer_name' => $contact?->name,
            'customer_email' => $contact?->email,
            'customer_user_id' => $contact?->id,
            'description' => $invoice->description,
            'total' => Money::format($invoice->total),
            'outstanding' => Money::format($invoice->outstanding()),
            'due_date' => $invoice->due_on?->format('d.m.Y') ?? '',
        ], null, 'invoice', $invoice->id);
    }

    /**
     * Dashboard/tahsilat toplamları — gerçek sayılar.
     *
     * @return array{revenue_today: int, revenue_month: int, outstanding: int, outstanding_count: int, overdue: int, overdue_count: int, due_7d_count: int}
     */
    public function dashboard(): array
    {
        $today = Carbon::today();
        $open = Invoice::withoutTenantScope()->whereIn('status', Invoice::OPEN)->get(['id', 'status', 'total', 'paid_amount', 'due_on']);
        $overdue = $open->where('status', 'overdue');

        return [
            'revenue_today' => (int) Payment::withoutTenantScope()->where('status', 'recorded')->whereDate('paid_on', $today->toDateString())->sum('amount'),
            'revenue_month' => (int) Payment::withoutTenantScope()->where('status', 'recorded')->whereDate('paid_on', '>=', $today->copy()->startOfMonth()->toDateString())->whereDate('paid_on', '<=', $today->copy()->endOfMonth()->toDateString())->sum('amount'),
            'outstanding' => (int) $open->sum(fn (Invoice $i) => $i->outstanding()),
            'outstanding_count' => $open->count(),
            'overdue' => (int) $overdue->sum(fn (Invoice $i) => $i->outstanding()),
            'overdue_count' => $overdue->count(),
            'due_7d_count' => $open->where('status', 'issued')->filter(fn (Invoice $i) => $i->due_on !== null && $i->due_on->lte($today->copy()->addDays(7)))->count(),
        ];
    }

    /**
     * Tahsilat ekranı: vade sırasıyla açık faturalar (gecikmiş önce).
     *
     * @return Collection<int, Invoice>
     */
    public function collectionList(int $limit = 50): Collection
    {
        return Invoice::withoutTenantScope()->whereIn('status', Invoice::OPEN)
            ->with(['company' => fn ($c) => $c->withoutGlobalScope(TenantScope::class)])
            ->orderByRaw("case when status = 'overdue' then 0 else 1 end")->orderBy('due_on')->limit($limit)->get();
    }

    /**
     * Aylık tahsilat serisi (son N ay) — rapor grafiği için gerçek toplamlar.
     *
     * @return array<int, array{month: string, amount: int}>
     */
    public function monthlyRevenue(int $months = 6): array
    {
        $start = Carbon::today()->startOfMonth()->subMonths($months - 1);
        $rows = Payment::withoutTenantScope()->where('status', 'recorded')->whereDate('paid_on', '>=', $start->toDateString())->get(['paid_on', 'amount'])->groupBy(fn (Payment $p) => $p->paid_on->format('Y-m'));
        $out = [];

        for ($i = 0; $i < $months; $i++) {
            $m = $start->copy()->addMonths($i);
            $out[] = ['month' => $m->format('Y-m'), 'amount' => (int) ($rows[$m->format('Y-m')] ?? collect())->sum('amount')];
        }

        return $out;
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    private function applyTab(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'open' => $query->whereIn('status', Invoice::OPEN),
            'overdue', 'paid', 'draft', 'cancelled' => $query->where('status', $tab),
            default => $query,
        };
    }

    private function blank(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
