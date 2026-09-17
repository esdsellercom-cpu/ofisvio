<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Scopes\TenantScope;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Money;
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
    public const TABS = ['open' => 'Tahsilat bekleyen', 'overdue' => 'Gecikmiş', 'paid' => 'Ödenen', 'draft' => 'Taslak', 'cancelled' => 'İptal', 'all' => 'Tümü'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly SettingsService $settings,
        private readonly NotificationService $notifications,
        private readonly MembershipService $members,
        private readonly CompanyActivationService $activation,
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

    public function cancel(User $actor, Invoice $invoice, string $reason): Invoice
    {
        if ($invoice->status === 'paid' || $invoice->status === 'cancelled') {
            throw new DomainException('Ödenmiş ya da iptal edilmiş fatura iptal edilemez.');
        }

        if ($invoice->paid_amount > 0) {
            throw new DomainException('Kısmi tahsilatı olan fatura iptal edilemez; önce tahsilatı iade/düzeltme kaydıyla kapatın.');
        }

        $before = $invoice->toArray();
        $invoice->fill(['status' => 'cancelled', 'cancelled_by' => $actor->id, 'cancelled_at' => Carbon::now(), 'cancel_reason' => $reason])->save();
        $this->audit->record($actor, 'invoice.cancelled', 'invoice', $invoice->id, $before, $invoice->toArray());

        return $invoice;
    }

    /**
     * Tahsilat kaydı (payment_allocation.manage ya da sağlayıcı webhook'u). Fazla ödeme reddedilir;
     * bakiye sıfırlanınca fatura paid.
     *
     * @param  array{amount: int|string, method: string, paid_on: string, reference?: string|null, note?: string|null}  $data  amount büyük birim (₺); webhook kuruş gönderiyorsa önce Money::major ile geçirilir
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

        return DB::transaction(function () use ($actor, $invoice, $data, $amount) {
            $payment = new Payment([
                'invoice_id' => $invoice->id,
                'company_id' => $invoice->company_id,
                'amount' => $amount,
                'method' => (string) $data['method'],
                'paid_on' => Carbon::parse($data['paid_on'])->toDateString(),
                'reference' => $this->blank($data['reference'] ?? null),
                'note' => $this->blank($data['note'] ?? null),
                'recorded_by' => $actor?->id,
            ]);
            $payment->save();

            $before = $invoice->toArray();
            $paid = $invoice->paid_amount + $amount;
            $invoice->fill(['paid_amount' => $paid] + ($paid >= $invoice->total ? ['status' => 'paid', 'paid_at' => Carbon::now()] : []))->save();

            $this->audit->record($actor, 'payment.recorded', 'payment', $payment->id, [], $payment->toArray());
            $this->audit->record($actor, $invoice->status === 'paid' ? 'invoice.paid' : 'invoice.partially_paid', 'invoice', $invoice->id, $before, $invoice->toArray());

            if ($invoice->status === 'paid') {
                DB::afterCommit(fn () => $this->notify('invoice.paid', $invoice));
            }

            return $payment;
        });
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
            'revenue_today' => (int) Payment::withoutTenantScope()->whereDate('paid_on', $today->toDateString())->sum('amount'),
            'revenue_month' => (int) Payment::withoutTenantScope()->whereDate('paid_on', '>=', $today->copy()->startOfMonth()->toDateString())->whereDate('paid_on', '<=', $today->copy()->endOfMonth()->toDateString())->sum('amount'),
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
        $rows = Payment::withoutTenantScope()->whereDate('paid_on', '>=', $start->toDateString())->get(['paid_on', 'amount'])->groupBy(fn (Payment $p) => $p->paid_on->format('Y-m'));
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
