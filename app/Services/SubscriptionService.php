<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Location;
use App\Models\Plan;
use App\Models\Scopes\TenantScope;
use App\Models\Space;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Money;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Üyelikler & paketler (faz 39b, artifact §6).
 *
 * Paketler (plans) panelden yönetilir; üyelik (subscription) şirketi bir pakete
 * tarih aralığıyla bağlar, fiyat anlık görüntü. Durum yalnız burada değişir:
 * active → cancelled (iptal) · active → expired (bitiş geçti, zamanlayıcı) ·
 * expired/cancelled → active (yenileme yeni dönem açar). Her değişiklik audit.
 *
 * withoutTenantScope: finans/genel liste ve sayaçlar (subscription.view GLOBAL
 * rotası — finance_admin/system_admin), zamanlayıcının süre dolumu ve dashboard
 * toplamları tüm şirketlere bakmak zorunda; müşteri tarafı forCompany() tenant
 * scope içinde kalır. (ArchitectureTest allowlist gerekçesi.)
 */
class SubscriptionService
{
    public const TABS = ['active' => 'Aktif', 'expiring' => 'Bitişi yaklaşan', 'expired' => 'Süresi dolan', 'cancelled' => 'İptal', 'all' => 'Tümü'];

    public const EXPIRING_DAYS = 30;

    public function __construct(
        private readonly AuditService $audit,
        private readonly SettingsService $settings,
        private readonly NotificationService $notifications,
        private readonly MembershipService $members,
        private readonly InvoiceService $invoices,
    ) {}

    // ---- Paketler ---------------------------------------------------------------

    /** @return Collection<int, Plan> */
    public function plans(bool $activeOnly = false): Collection
    {
        return Plan::query()->with('service')->withCount('subscriptions')
            ->when($activeOnly, fn (Builder $q) => $q->where('is_active', true))
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    public function findPlan(int $id): ?Plan
    {
        return Plan::query()->find($id);
    }

    /** @param  array{name: string, summary?: string|null, features?: string|null, price: int|string, period: string, service_id?: int|null, is_active?: bool, sort_order?: int|null}  $data  price büyük birim (₺), kuruşa çevrilir */
    public function createPlan(User $actor, array $data): Plan
    {
        $plan = new Plan($this->planAttributes($data));
        $plan->slug = $this->uniqueSlug($data['name']);
        $plan->updated_by = $actor->id;
        $plan->save();
        $this->audit->record($actor, 'plan.created', 'plan', $plan->id, [], $plan->toArray());

        return $plan;
    }

    /** @param  array<string, mixed>  $data */
    public function updatePlan(User $actor, Plan $plan, array $data): Plan
    {
        $before = $plan->toArray();
        $plan->fill($this->planAttributes($data));
        $plan->updated_by = $actor->id;
        $plan->save();
        $this->audit->record($actor, 'plan.updated', 'plan', $plan->id, $before, $plan->toArray());

        return $plan;
    }

    /** Üyeliği olan paket silinemez (geçmiş korunur); pasife alınır. */
    public function deletePlan(User $actor, Plan $plan): void
    {
        if ($plan->subscriptions()->withoutGlobalScope(TenantScope::class)->exists()) {
            throw new DomainException('Bu pakete bağlı üyelik var; silinemez, pasife alın.');
        }

        $before = $plan->toArray();
        $plan->delete();
        $this->audit->record($actor, 'plan.deleted', 'plan', $plan->id, $before, []);
    }

    // ---- Üyelikler --------------------------------------------------------------

    /**
     * Personel listesi (subscription.view global): sekme + şirket/paket süzgeci.
     *
     * @param  array{tab?: string|null, plan_id?: int|string|null, q?: string|null}  $filters
     * @return LengthAwarePaginator<int, Subscription>
     */
    public function paginateAll(array $filters, int $perPage = 50): LengthAwarePaginator
    {
        $tab = (string) ($filters['tab'] ?? 'active');
        $q = trim((string) ($filters['q'] ?? ''));

        $query = Subscription::withoutTenantScope()
            ->when($filters['plan_id'] ?? null, fn (Builder $b, $id) => $b->where('plan_id', (int) $id))
            ->when($q !== '', fn (Builder $b) => $b->whereHas('company', fn (Builder $c) => $c->withoutGlobalScope(TenantScope::class)->where('legal_name', 'like', "%{$q}%")));

        $this->applyTab($query, $tab);

        return $query
            ->with(['plan', 'location', 'company' => fn ($c) => $c->withoutGlobalScope(TenantScope::class)])
            ->orderBy($tab === 'expiring' || $tab === 'active' ? 'ends_on' : 'updated_at', $tab === 'expiring' || $tab === 'active' ? 'asc' : 'desc')
            ->paginate($perPage)->withQueryString();
    }

    /** @return array<string, int> */
    public function tabCounts(): array
    {
        $out = [];

        foreach (array_keys(self::TABS) as $tab) {
            if ($tab === 'all') {
                continue;
            }

            $out[$tab] = $this->applyTab(Subscription::withoutTenantScope(), $tab)->count();
        }

        return $out;
    }

    /**
     * Menü rozeti: bitişi yaklaşan aktif üyelikler (PanelBadgeService tek sorguda sayar).
     *
     * @return Builder<Subscription>
     */
    public function expiringQuery(): Builder
    {
        return $this->applyTab(Subscription::withoutTenantScope(), 'expiring');
    }

    /**
     * Personel (fatura formu): şirketin üyelikleri, şirket bağlamı olmadan (subscription.view global).
     *
     * @return Collection<int, Subscription>
     */
    public function forCompanyAny(int $companyId): Collection
    {
        return Subscription::withoutTenantScope()->where('company_id', $companyId)->with('plan')->orderByDesc('ends_on')->get();
    }

    /**
     * Tahsis formu (faz 46): verilen şirketlerin aktif üyelikleri tek sorguda.
     *
     * @param  array<int, int>  $companyIds
     * @return Collection<int, Subscription>
     */
    public function activeForCompanies(array $companyIds): Collection
    {
        if ($companyIds === []) {
            return new Collection;
        }

        return Subscription::withoutTenantScope()->whereIn('company_id', $companyIds)->where('status', 'active')->with('plan')->orderBy('company_id')->orderByDesc('ends_on')->get();
    }

    public function findAny(int $id): ?Subscription
    {
        return Subscription::withoutTenantScope()->with(['plan', 'location', 'creator', 'company' => fn ($c) => $c->withoutGlobalScope(TenantScope::class)])->find($id);
    }

    /**
     * Müşteri tarafı: şirketin üyelikleri (tenant scope içinde).
     *
     * @return Collection<int, Subscription>
     */
    public function forCompany(Company $company): Collection
    {
        return Subscription::query()->where('company_id', $company->id)->with(['plan', 'location'])->orderByDesc('ends_on')->get();
    }

    /**
     * Personel: şirkete üyelik açar (subscription.manage). Askıdaki/fesihli şirkete açılmaz;
     * aynı paket için çakışan aktif üyelik varsa reddedilir.
     *
     * @param  array{starts_on: string, months: int, location_id?: int|string|null, auto_renew?: bool, issue_invoice?: bool, note?: string|null}  $data  issue_invoice: ilk dönem faturası hemen yayınlanır
     */
    public function create(User $actor, Company $company, Plan $plan, array $data): Subscription
    {
        if (in_array($company->status, BookingService::BLOCKED_COMPANY_STATUSES, true)) {
            throw new DomainException('Askıda ya da fesih sürecindeki şirkete üyelik açılamaz.');
        }

        if (! $plan->is_active) {
            throw new DomainException('Pasif pakete üyelik açılamaz.');
        }

        $starts = Carbon::parse($data['starts_on'])->startOfDay();
        $months = max(1, (int) $data['months']);
        $ends = $starts->copy()->addMonths($months)->subDay();

        $overlap = Subscription::withoutTenantScope()->where('company_id', $company->id)->where('plan_id', $plan->id)
            ->where('status', 'active')->where('ends_on', '>=', $starts->toDateString())->where('starts_on', '<=', $ends->toDateString())->exists();

        if ($overlap) {
            throw new DomainException('Şirketin bu pakette çakışan aktif üyeliği var; önce yenileyin ya da iptal edin.');
        }

        $location = ! empty($data['location_id']) ? Location::query()->find((int) $data['location_id']) : null;

        $sub = new Subscription([
            'company_id' => $company->id,
            'plan_id' => $plan->id,
            'location_id' => $location?->id,
            'status' => 'active',
            'starts_on' => $starts->toDateString(),
            'ends_on' => $ends->toDateString(),
            'price' => $plan->price,
            'period' => $plan->period,
            'auto_renew' => (bool) ($data['auto_renew'] ?? true),
            'note' => $this->blank($data['note'] ?? null),
            'created_by' => $actor->id,
        ]);
        $sub->save();
        $this->audit->record($actor, 'subscription.created', 'subscription', $sub->id, [], $sub->toArray());

        if (! empty($data['issue_invoice']) && $sub->price > 0) {
            $this->invoices->createSystem($company, [
                'description' => $plan->name.' · '.$sub->starts_on->format('d.m.Y').' – '.$sub->ends_on->format('d.m.Y'),
                'subtotal_minor' => $sub->price,
                'subscription_id' => $sub->id,
            ], $actor);
        }

        return $sub;
    }

    public function cancel(User $actor, Subscription $sub, string $reason): Subscription
    {
        if (! $sub->isActive()) {
            throw new DomainException('Yalnız aktif üyelik iptal edilir.');
        }

        $before = $sub->toArray();
        $sub->fill(['status' => 'cancelled', 'cancelled_by' => $actor->id, 'cancelled_at' => Carbon::now(), 'cancel_reason' => $reason, 'auto_renew' => false])->save();
        $this->audit->record($actor, 'subscription.cancelled', 'subscription', $sub->id, $before, $sub->toArray());

        return $sub;
    }

    /** Yenileme: bitişten (ya da bugünden, hangisi geçse) itibaren yeni dönem; paketin GÜNCEL fiyatı yazılır. */
    public function renew(User $actor, Subscription $sub, int $months): Subscription
    {
        if ($sub->status === 'cancelled') {
            throw new DomainException('İptal edilmiş üyelik yenilenmez; yeni üyelik açın.');
        }

        $months = max(1, $months);
        $from = $sub->ends_on->copy()->addDay()->max(Carbon::today());
        $before = $sub->toArray();
        $plan = $sub->plan;
        $sub->fill([
            'status' => 'active',
            'ends_on' => $from->copy()->addMonths($months)->subDay()->toDateString(),
            'price' => $plan->price,
            'period' => $plan->period,
        ])->save();
        $this->audit->record($actor, 'subscription.renewed', 'subscription', $sub->id, $before, $sub->toArray());

        return $sub;
    }

    /**
     * Zamanlayıcı (audit P1-5): bitişi geçen ve auto_renew açık üyelikler yeni döneme geçer (bitişten
     * itibaren, paketin güncel fiyatı), isteğe bağlı fatura yayınlanır (finance.auto_invoice_on_renewal),
     * subscription.renewed bildirilir. Paket pasifse yenilenmez → expireStale kapatır.
     */
    public function renewDue(): int
    {
        $n = 0;

        foreach (Subscription::withoutTenantScope()->where('status', 'active')->where('auto_renew', true)->whereDate('ends_on', '<', Carbon::today()->toDateString())->with('plan')->get() as $sub) {
            if (! $sub->plan->is_active) {
                continue;
            }

            $before = $sub->toArray();
            $from = $sub->ends_on->copy()->addDay();
            $months = $sub->plan->period === 'yearly' ? 12 : 1;
            $sub->fill([
                'starts_on' => $from->toDateString(),
                'ends_on' => $from->copy()->addMonths($months)->subDay()->toDateString(),
                'price' => $sub->plan->price,
                'period' => $sub->plan->period,
                'renewal_count' => $sub->renewal_count + 1,
                'expiring_notice_sent_at' => null,
            ])->save();
            $this->audit->record(null, 'subscription.renewed', 'subscription', $sub->id, $before, $sub->toArray());

            if ($this->settings->bool('finance.auto_invoice_on_renewal') && $sub->price > 0) {
                $company = Company::withoutTenantScope()->find($sub->company_id);

                if ($company !== null) {
                    $this->invoices->createSystem($company, [
                        'description' => $sub->plan->name.' · '.$sub->starts_on->format('d.m.Y').' – '.$sub->ends_on->format('d.m.Y'),
                        'subtotal_minor' => $sub->price,
                        'subscription_id' => $sub->id,
                    ]);
                }
            }

            $this->notify('subscription.renewed', $sub);
            $n++;
        }

        return $n;
    }

    /** Zamanlayıcı (audit P1-8): bitişe N gün kala tek seferlik bildirim (subscription.expiring_notice_days; 0 = kapalı). */
    public function remindExpiring(): int
    {
        $days = $this->settings->int('subscription.expiring_notice_days');

        if ($days <= 0) {
            return 0;
        }

        $n = 0;
        $limit = Carbon::today()->addDays($days)->toDateString();

        foreach (Subscription::withoutTenantScope()->where('status', 'active')->whereNull('expiring_notice_sent_at')->whereDate('ends_on', '<=', $limit)->whereDate('ends_on', '>=', Carbon::today()->toDateString())->with('plan')->get() as $sub) {
            $sub->fill(['expiring_notice_sent_at' => Carbon::now()])->save();
            $this->notify('subscription.expiring', $sub);
            $n++;
        }

        return $n;
    }

    /** Zamanlayıcı: bitişi geçmiş aktif üyelikler expired (auto_renew olanlar önce renewDue ile yenilenir). */
    public function expireStale(): int
    {
        $n = 0;

        foreach (Subscription::withoutTenantScope()->where('status', 'active')->whereDate('ends_on', '<', Carbon::today()->toDateString())->with('plan')->get() as $sub) {
            $before = $sub->toArray();
            $sub->fill(['status' => 'expired'])->save();
            $this->audit->record(null, 'subscription.expired', 'subscription', $sub->id, $before, $sub->toArray());
            $this->notify('subscription.expired', $sub);
            $n++;
        }

        return $n;
    }

    /** Üyelik olayını müşteri muhatabı + finans grubuna gönderir. */
    private function notify(string $event, Subscription $sub): void
    {
        $company = Company::withoutTenantScope()->find($sub->company_id);
        $contact = $this->members->primaryContact($sub->company_id);

        $this->notifications->dispatch($event, [
            'plan' => (string) ($sub->plan->name ?? ''),
            'company_name' => (string) ($company->legal_name ?? ''),
            'customer_name' => $contact?->name,
            'customer_email' => $contact?->email,
            'customer_user_id' => $contact?->id,
            'starts' => $sub->starts_on->format('d.m.Y'),
            'ends' => $sub->ends_on->format('d.m.Y'),
            'price' => Money::format($sub->price).' / '.(Plan::PERIODS[$sub->period] ?? $sub->period),
            'renewal' => $sub->auto_renew ? 'dönem sonunda otomatik yenilenir' : 'yenilenmeyecek',
        ], null, 'subscription', $sub->id);
    }

    /**
     * Dashboard/rapor toplamları — gerçek sayılar.
     *
     * @return array{active: int, expiring: int, new_30d: int, mrr: int, by_plan: array<int, array{name: string, count: int}>}
     */
    public function dashboard(): array
    {
        $active = Subscription::withoutTenantScope()->where('status', 'active')->with('plan')->get();

        return [
            'active' => $active->count(),
            'expiring' => $this->expiringQuery()->count(), // sekme/rozet ile aynı tanım (bugünden ileri 30 gün)
            'new_30d' => Subscription::withoutTenantScope()->where('created_at', '>=', Carbon::now()->subDays(30))->count(),
            'mrr' => (int) $active->sum(fn (Subscription $s) => $s->monthlyPrice()),
            'by_plan' => $active->groupBy('plan_id')->map(fn (Collection $g) => ['name' => (string) ($g->first()?->plan->name ?? '—'), 'count' => $g->count()])->values()->all(),
        ];
    }

    /**
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    private function applyTab(Builder $query, string $tab): Builder
    {
        $today = Carbon::today()->toDateString();

        return match ($tab) {
            'active' => $query->where('status', 'active'),
            'expiring' => $query->where('status', 'active')->whereDate('ends_on', '>=', $today)->whereDate('ends_on', '<=', Carbon::today()->addDays(self::EXPIRING_DAYS)->toDateString()),
            'expired' => $query->where('status', 'expired'),
            'cancelled' => $query->where('status', 'cancelled'),
            default => $query,
        };
    }

    /** @param  array<string, mixed>  $data  @return array<string, mixed> */
    private function planAttributes(array $data): array
    {
        $period = (string) ($data['period'] ?? 'monthly');

        if (! isset(Plan::PERIODS[$period])) {
            throw new DomainException('Geçersiz dönem.');
        }

        return [
            'name' => trim((string) $data['name']),
            'summary' => $this->blank($data['summary'] ?? null),
            'features' => $this->blank($data['features'] ?? null),
            'price' => max(0, Money::parse((string) $data['price'])),
            'period' => $period,
            'service_id' => ! empty($data['service_id']) ? (int) $data['service_id'] : null,
            'space_kind' => ! empty($data['space_kind']) && isset(Space::KINDS[(string) $data['space_kind']]) ? (string) $data['space_kind'] : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'paket';
        $slug = $base;

        for ($i = 2; Plan::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    private function blank(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
