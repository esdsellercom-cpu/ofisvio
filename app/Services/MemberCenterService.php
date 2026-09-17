<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Document;
use App\Models\ExtraCharge;
use App\Models\Invoice;
use App\Models\MemberProfile;
use App\Models\Payment;
use App\Models\SpaceAssignment;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserRole;
use App\Support\Money;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 360° üye merkezi (faz 51): üye (şirket üyeliği = user_roles) etrafında finans, sözleşme, hizmet (üyelik), tahsis,
 * demirbaş, belge ve aktivite tek yerde. Finansal/operasyonel kayıtlar ŞİRKET bazlıdır (fatura, tahsilat, üyelik,
 * tahsis şirkete kesilir); üye profili kişi bilgisini taşır. Yazan yollar mevcut servislerdir (InvoiceService,
 * SubscriptionService, SpaceService, AssetService, DocumentService); burası yalnız profil, sözleşme ve ek harcamayı
 * yazar, kalanını okuyup birleştirir. Görünürlük CompanyService::visibleTo; yetki route'ta.
 */
class MemberCenterService
{
    public const CONTRACT_DISK = 'local';

    public function __construct(
        private readonly CompanyService $companies,
        private readonly MembershipService $members,
        private readonly AuthorizationService $authorization,
        private readonly InvoiceService $invoices,
        private readonly SubscriptionService $subscriptions,
        private readonly SpaceService $spaces,
        private readonly DocumentService $documents,
        private readonly MediaService $media,
        private readonly SettingsService $settings,
        private readonly ContentService $contents,
        private readonly AuditService $audit,
        private readonly TenantContext $context,
    ) {}

    // ---- Görünürlük ------------------------------------------------------------------

    /** Görebildiği şirketlerden üyelik; başkasınınki null (404). */
    public function find(User $viewer, int $memberId): ?UserRole
    {
        $member = UserRole::query()->with(['user', 'role', 'company', 'profile.avatar'])->whereNotNull('company_id')->find($memberId);

        if ($member === null || ! $this->companies->visibleTo($viewer)->contains('id', $member->company_id)) {
            return null;
        }

        return $member;
    }

    /**
     * Üye ekleyebildiği şirketler (membership.manage — global personel hepsi, sahip kendi şirketi).
     *
     * @return Collection<int, Company>
     */
    public function manageableCompanies(User $user): Collection
    {
        $organizationId = $this->context->requireOrganization($user)->id;

        return $this->companies->visibleTo($user)->filter(fn (Company $c) => $this->authorization->can($user, 'membership.manage', ['organization_id' => $organizationId, 'company_id' => $c->id]))->values();
    }

    // ---- Dizin ------------------------------------------------------------------------

    /**
     * Üye dizini: görünür şirketlerin üyeleri + şirket başına finans özeti + aktif sözleşme; süzgeç ve arama.
     *
     * @param  array{q?: string, filter?: string}  $filters
     * @return array{rows: Collection<int, UserRole>, finance: array<int, array<string, mixed>>, contracts: array<int, Contract|null>, counts: array<string, int>}
     */
    public function directory(User $viewer, array $filters = []): array
    {
        $companies = $this->companies->visibleTo($viewer);
        $rows = $this->members->membersOfCompanies($companies)->load('profile.avatar');
        $finance = $this->financeByCompany($companies->modelKeys());
        $contracts = $this->activeContractsByCompany($companies->modelKeys());
        $q = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        $filter = (string) ($filters['filter'] ?? '');

        if ($q !== '') {
            $rows = $rows->filter(function (UserRole $m) use ($q) {
                $hay = mb_strtolower(implode(' ', [$m->user->name, $m->user->email, $m->company->legal_name, (string) ($m->profile->phone ?? ''), (string) ($m->profile->member_no ?? '')]));

                return str_contains($hay, $q) || str_contains(preg_replace('/\D+/', '', $hay) ?? '', preg_replace('/\D+/', '', $q) ?? '') && preg_replace('/\D+/', '', $q) !== '';
            });
        }

        $today = Carbon::today();
        $rows = $rows->filter(function (UserRole $m) use ($filter, $finance, $contracts, $today) {
            $f = $finance[$m->company_id] ?? self::emptyFinance();
            $c = $contracts[$m->company_id] ?? null;

            return match ($filter) {
                'active' => $m->isActive(),
                'passive' => ! $m->isActive(),
                'debtor' => $f['remaining'] > 0,
                'credit' => $f['balance'] > 0,
                'contract_soon' => $c !== null && $c->daysLeft() !== null && $c->daysLeft() >= 0 && $c->daysLeft() <= 30,
                'contract_over' => $c === null ? Contract::query()->where('company_id', $m->company_id)->whereIn('status', ['ended'])->orWhere(fn ($q) => $q->where('company_id', $m->company_id)->where('status', 'active')->whereDate('ends_on', '<', $today))->exists() : ($c->daysLeft() !== null && $c->daysLeft() < 0),
                'new' => $m->created_at !== null && $m->created_at->gte($today->copy()->subDays(30)),
                default => true,
            };
        })->values();

        return [
            'rows' => $rows,
            'finance' => $finance,
            'contracts' => $contracts,
            'counts' => [
                'companies' => $companies->count(), 'members' => $rows->count(),
                'active' => $rows->filter(fn (UserRole $m) => $m->isActive())->count(),
                'debtors' => $rows->filter(fn (UserRole $m) => ($finance[$m->company_id]['remaining'] ?? 0) > 0)->pluck('company_id')->unique()->count(),
                'debt_total' => (int) array_sum(array_map(fn ($f) => $f['remaining'], array_intersect_key($finance, array_flip($rows->pluck('company_id')->unique()->all())))),
            ],
        ];
    }

    // ---- Üye oluşturma / güncelleme -----------------------------------------------------

    /**
     * Yeni üye: davet (MembershipService::invite — kullanıcı + rol + org üyeliği) + profil (üye no, kişi bilgileri) +
     * isteğe bağlı ilk sözleşme; avatar medya kütüphanesine (karantina zinciri).
     *
     * @param  array<string, mixed>  $data
     */
    public function createMember(User $actor, Company $company, array $data, ?UploadedFile $avatar = null): UserRole
    {
        $name = trim(trim((string) $data['first_name']).' '.trim((string) $data['last_name']));
        $result = $this->members->invite($actor, $company, ['email' => (string) $data['email'], 'name' => $name, 'role' => (string) ($data['role'] ?? 'employee')]);
        $member = $result['role'];

        if (($data['status'] ?? 'active') === 'suspended') {
            $this->members->suspend($actor, $company, $member);
        }

        $this->saveProfile($actor, $member, $data, $avatar, true);

        if (! empty($data['contract_starts_on'])) {
            $this->createContract($actor, $company, $member, ['type' => (string) ($data['contract_type'] ?? 'uyelik'), 'starts_on' => (string) $data['contract_starts_on'], 'ends_on' => $data['contract_ends_on'] ?? null, 'status' => 'active', 'note' => null]);
        }

        $this->audit->record($actor, 'member.created', 'user_role', $member->id, [], ['company_id' => $company->id, 'user_id' => $member->user_id, 'invited' => $result['invited']]);

        return $member->load(['user', 'role', 'company', 'profile']);
    }

    /** @param  array<string, mixed>  $data */
    public function updateMember(User $actor, UserRole $member, array $data, ?UploadedFile $avatar = null): UserRole
    {
        $name = trim(trim((string) $data['first_name']).' '.trim((string) $data['last_name']));
        $before = ['name' => $member->user->name, 'status' => $member->status];

        if ($name !== '' && $name !== $member->user->name) {
            $member->user->forceFill(['name' => $name])->save();
        }

        if (isset($data['status'])) {
            if ($data['status'] === 'suspended' && $member->isActive()) {
                $this->members->suspend($actor, $member->company, $member);
            } elseif ($data['status'] === 'active' && ! $member->isActive()) {
                $this->members->reactivate($member->company, $member);
            }
        }

        $this->saveProfile($actor, $member, $data, $avatar, false);
        $this->audit->record($actor, 'member.updated', 'user_role', $member->id, $before, ['name' => $name, 'status' => $member->fresh()->status]);

        return $member->refresh()->load(['user', 'role', 'company', 'profile']);
    }

    /** @param  array<string, mixed>  $data */
    private function saveProfile(User $actor, UserRole $member, array $data, ?UploadedFile $avatar, bool $creating): void
    {
        $profile = MemberProfile::query()->firstOrNew(['user_role_id' => $member->id]);

        if (! $profile->exists) {
            $profile->member_no = $this->nextMemberNo();
        }

        $fields = ['first_name', 'last_name', 'title', 'phone', 'identity_number', 'address', 'city', 'country', 'membership_type', 'note'];

        foreach ($fields as $key) {
            if (array_key_exists($key, $data)) {
                $profile->{$key} = self::blank($data[$key]);
            }
        }

        if (array_key_exists('member_since', $data)) {
            $profile->member_since = ! empty($data['member_since']) ? Carbon::parse((string) $data['member_since'])->startOfDay() : null;
        }

        if ($profile->membership_type !== null && ! isset(MemberProfile::MEMBERSHIP_TYPES[$profile->membership_type])) {
            throw new DomainException('Geçersiz üyelik tipi.');
        }

        if ($avatar !== null) {
            $website = $this->contents->defaultWebsite();
            $profile->avatar_media_id = $this->media->upload($actor, $website, $avatar, ['alt' => $member->user->name.' profil görseli', 'seo_name' => 'uye-'.strtolower($profile->member_no)])->id;
        }

        $profile->updated_by = $actor->id;
        $profile->save();
    }

    private function nextMemberNo(): string
    {
        $last = (int) (MemberProfile::query()->lockForUpdate()->max('id') ?? 0);

        do {
            $candidate = sprintf('UYE-%06d', ++$last);
        } while (MemberProfile::query()->where('member_no', $candidate)->exists());

        return $candidate;
    }

    // ---- Finans -----------------------------------------------------------------------

    /** @return array{invoiced: int, paid: int, remaining: int, balance: int, pending: int, overdue: int, uninvoiced: int, last_payment: ?Carbon, last_payment_amount: int, payments_total: int, currency: string} */
    public static function emptyFinance(): array
    {
        return ['invoiced' => 0, 'paid' => 0, 'remaining' => 0, 'balance' => 0, 'pending' => 0, 'overdue' => 0, 'uninvoiced' => 0, 'last_payment' => null, 'last_payment_amount' => 0, 'payments_total' => 0, 'currency' => Money::currency()];
    }

    /**
     * Şirket başına finans özeti (tek geçişte, N+1 yok). Toplam borç = yayınlanmış/ödenmiş fatura toplamları +
     * faturasız ek harcamalar; ödenen = fatura paid_amount; kalan = açık fatura bakiyesi + faturasız ek harcama;
     * bakiye = ödenen − toplam borç (pozitifse alacak).
     *
     * @param  array<int, int>  $companyIds
     * @return array<int, array<string, mixed>>
     */
    public function financeByCompany(array $companyIds): array
    {
        $out = [];

        if ($companyIds === []) {
            return $out;
        }

        $today = Carbon::today()->toDateString();

        foreach ($companyIds as $id) {
            $out[$id] = self::emptyFinance();
        }

        foreach (Invoice::query()->whereIn('company_id', $companyIds)->whereIn('status', ['issued', 'overdue', 'paid'])->get(['company_id', 'status', 'total', 'paid_amount', 'due_on', 'currency']) as $inv) {
            $out[$inv->company_id]['currency'] = $inv->currency;
            $out[$inv->company_id]['invoiced'] += $inv->total;
            $out[$inv->company_id]['paid'] += $inv->paid_amount;

            if ($inv->isOpen()) {
                $open = $inv->outstanding();
                $out[$inv->company_id]['remaining'] += $open;
                $overdue = $inv->status === 'overdue' || ($inv->getAttribute('due_on') !== null && $inv->due_on->toDateString() < $today);
                $out[$inv->company_id][$overdue ? 'overdue' : 'pending'] += $open;
            }
        }

        foreach (ExtraCharge::query()->whereIn('company_id', $companyIds)->whereNull('invoice_id')->get(['company_id', 'amount']) as $charge) {
            $out[$charge->company_id]['invoiced'] += $charge->amount;
            $out[$charge->company_id]['remaining'] += $charge->amount;
            $out[$charge->company_id]['uninvoiced'] += $charge->amount;
        }

        foreach (Payment::query()->whereIn('company_id', $companyIds)->where('status', 'recorded')->orderBy('paid_on')->orderBy('id')->get(['company_id', 'amount', 'paid_on']) as $p) {
            $out[$p->company_id]['payments_total'] += $p->amount;
            $out[$p->company_id]['last_payment'] = $p->paid_on;
            $out[$p->company_id]['last_payment_amount'] = $p->amount;
        }

        foreach (array_keys($out) as $id) {
            $out[$id]['balance'] = max(0, $out[$id]['paid'] - $out[$id]['invoiced']);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function finance(Company $company): array
    {
        return $this->financeByCompany([$company->id])[$company->id] ?? self::emptyFinance();
    }

    /**
     * Finansal hareketler (tek zaman çizelgesi): fatura (−), tahsilat (+), faturasız ek harcama (−), iptaller.
     *
     * @return array<int, array{date: Carbon, amount: int, type: string, label: string, description: string, document: array{label: string, url: string}|null, actor: string, cancelled: bool}>
     */
    public function ledger(Company $company): array
    {
        $rows = [];

        foreach (Invoice::query()->where('company_id', $company->id)->where('status', '!=', 'draft')->with('creator')->get() as $inv) {
            $rows[] = ['date' => Carbon::parse($inv->issued_on ?? $inv->created_at), 'amount' => -$inv->total, 'type' => 'invoice', 'label' => 'Fatura '.$inv->number, 'description' => (string) $inv->description, 'document' => ['label' => $inv->number, 'url' => route('panel.invoices.show', $inv)], 'actor' => (string) ($inv->creator->name ?? 'Sistem'), 'cancelled' => $inv->status === 'cancelled'];
        }

        foreach (Payment::query()->where('company_id', $company->id)->with(['recorder', 'invoice', 'receipt'])->get() as $p) {
            $rows[] = ['date' => Carbon::parse($p->paid_on), 'amount' => $p->amount, 'type' => 'payment', 'label' => 'Tahsilat ('.$p->methodLabel().')', 'description' => trim(($p->invoice->number ?? '').' '.(string) ($p->description ?? '')), 'document' => $p->receipt !== null ? ['label' => $p->receipt->number, 'url' => route('panel.collections.documents.show', $p->receipt)] : null, 'actor' => (string) ($p->recorder->name ?? 'Sistem'), 'cancelled' => $p->isCancelled()];
        }

        foreach (ExtraCharge::query()->where('company_id', $company->id)->with(['creator', 'invoice'])->get() as $c) {
            $rows[] = ['date' => Carbon::parse($c->charged_on), 'amount' => $c->invoice_id === null ? -$c->amount : 0, 'type' => 'charge', 'label' => 'Ek harcama · '.$c->kindLabel().($c->invoice_id !== null ? ' (faturalandı)' : ''), 'description' => $c->description, 'document' => $c->invoice !== null ? ['label' => $c->invoice->number, 'url' => route('panel.invoices.show', $c->invoice)] : null, 'actor' => (string) ($c->creator->name ?? '—'), 'cancelled' => false];
        }

        usort($rows, fn ($a, $b) => $b['date'] <=> $a['date']);

        return $rows;
    }

    /**
     * Ek harcama: kayıt + faturalama seçeneği (`invoice` = InvoiceService ile yeni fatura kes ve yayınla,
     * `link:ID` = mevcut faturaya bağla — tutar o faturada olmalı, `none` = faturasız bakiye hareketi).
     *
     * @param  array{kind: string, description: string, amount: string, charged_on: string, billing?: string, note?: string|null}  $data
     */
    public function addExtraCharge(User $actor, Company $company, ?UserRole $member, array $data): ExtraCharge
    {
        if (! isset(ExtraCharge::KINDS[$data['kind']])) {
            throw new DomainException('Geçersiz harcama türü.');
        }

        $amount = Money::parse($data['amount']);

        if ($amount <= 0) {
            throw new DomainException('Tutar sıfırdan büyük olmalı.');
        }

        $billing = (string) ($data['billing'] ?? 'none');
        $invoiceId = null;

        if ($billing === 'invoice') {
            $invoice = $this->invoices->create($actor, $company, ['description' => 'Ek harcama: '.ExtraCharge::KINDS[$data['kind']].' — '.$data['description'], 'subtotal' => Money::major($amount), 'issue' => true]);
            $invoiceId = $invoice->id;
        } elseif (str_starts_with($billing, 'link:')) {
            $invoice = $this->invoices->findForCompany($company, (int) substr($billing, 5));

            if ($invoice === null) {
                throw new DomainException('Bağlanacak fatura bu şirkete ait değil.');
            }

            $invoiceId = $invoice->id;
        }

        $charge = ExtraCharge::query()->create([
            'company_id' => $company->id, 'user_role_id' => $member?->id, 'kind' => $data['kind'], 'description' => mb_substr(trim($data['description']), 0, 200),
            'amount' => $amount, 'currency' => $this->settings->string('general.currency'), 'charged_on' => Carbon::parse($data['charged_on'])->toDateString(),
            'invoice_id' => $invoiceId, 'note' => self::blank($data['note'] ?? null), 'created_by' => $actor->id,
        ]);
        $this->audit->record($actor, 'extra_charge.created', 'extra_charge', $charge->id, [], ['company_id' => $company->id, 'kind' => $charge->kind, 'amount' => $amount, 'invoice_id' => $invoiceId]);

        return $charge;
    }

    // ---- Sözleşmeler -----------------------------------------------------------------

    /** @return Collection<int, Contract> */
    public function contracts(Company $company): Collection
    {
        return Contract::query()->where('company_id', $company->id)->with(['membership.user', 'creator', 'documents'])->orderByRaw("case when status = 'active' then 0 else 1 end")->orderByDesc('starts_on')->get();
    }

    /** @return array<int, Contract|null> şirket → aktif (en geç biten) sözleşme */
    private function activeContractsByCompany(array $companyIds): array
    {
        $out = [];

        foreach (Contract::query()->whereIn('company_id', $companyIds)->where('status', 'active')->orderBy('ends_on')->get() as $c) {
            $out[$c->company_id] = $c; // sonuncu = en geç biten
        }

        return $out;
    }

    public function findContract(Company $company, int $id): Contract
    {
        $contract = Contract::query()->where('company_id', $company->id)->find($id);

        if ($contract === null) {
            throw new DomainException('Sözleşme bulunamadı.');
        }

        return $contract;
    }

    /** @param  array{type: string, starts_on: string, ends_on?: string|null, status?: string|null, note?: string|null}  $data */
    public function createContract(User $actor, Company $company, ?UserRole $member, array $data, ?UploadedFile $file = null): Contract
    {
        $this->assertContractData($data);

        $contract = DB::transaction(function () use ($actor, $company, $member, $data) {
            $year = Carbon::today()->format('Y');
            $last = (int) Contract::withoutTenantScope()->where('number', 'like', "SOZ-{$year}-%")->lockForUpdate()->count();
            $number = sprintf('SOZ-%s-%06d', $year, $last + 1);

            while (Contract::withoutTenantScope()->where('number', $number)->exists()) {
                $number = sprintf('SOZ-%s-%06d', $year, ++$last + 1);
            }

            return Contract::query()->create([
                'company_id' => $company->id, 'user_role_id' => $member?->id, 'number' => $number, 'type' => $data['type'],
                'starts_on' => Carbon::parse($data['starts_on'])->toDateString(), 'ends_on' => ! empty($data['ends_on']) ? Carbon::parse($data['ends_on'])->toDateString() : null,
                'status' => in_array($data['status'] ?? 'active', ['draft', 'active'], true) ? $data['status'] ?? 'active' : 'active', 'note' => self::blank($data['note'] ?? null), 'created_by' => $actor->id,
            ]);
        });

        if ($file !== null) {
            $this->attachContractFile($contract, $file);
        }

        $this->audit->record($actor, 'contract.created', 'contract', $contract->id, [], ['company_id' => $company->id, 'number' => $contract->number, 'type' => $contract->type, 'starts_on' => $contract->starts_on->toDateString(), 'ends_on' => $contract->ends_on?->toDateString()]);

        return $contract;
    }

    /** @param  array{type: string, starts_on: string, ends_on?: string|null, status?: string|null, note?: string|null}  $data */
    public function updateContract(User $actor, Contract $contract, array $data, ?UploadedFile $file = null): Contract
    {
        if ($contract->status === 'ended') {
            throw new DomainException('Sonlandırılmış sözleşme düzenlenemez.');
        }

        $this->assertContractData($data);
        $before = $contract->only(['type', 'starts_on', 'ends_on', 'status', 'note']);
        $contract->fill([
            'type' => $data['type'], 'starts_on' => Carbon::parse($data['starts_on'])->toDateString(), 'ends_on' => ! empty($data['ends_on']) ? Carbon::parse($data['ends_on'])->toDateString() : null,
            'status' => in_array($data['status'] ?? $contract->status, ['draft', 'active'], true) ? ($data['status'] ?? $contract->status) : $contract->status, 'note' => self::blank($data['note'] ?? null),
        ])->save();

        if ($file !== null) {
            $this->attachContractFile($contract, $file);
        }

        $this->audit->record($actor, 'contract.updated', 'contract', $contract->id, $before, $contract->only(['type', 'starts_on', 'ends_on', 'status', 'note']));

        return $contract;
    }

    public function endContract(User $actor, Contract $contract, string $reason): Contract
    {
        if ($contract->status === 'ended') {
            throw new DomainException('Sözleşme zaten sonlandırılmış.');
        }

        $contract->forceFill(['status' => 'ended', 'ended_by' => $actor->id, 'ended_at' => Carbon::now(), 'end_reason' => mb_substr(trim($reason), 0, 200)])->save();
        $this->audit->record($actor, 'contract.ended', 'contract', $contract->id, ['status' => 'active'], ['status' => 'ended', 'reason' => $reason]);

        return $contract;
    }

    /** Sözleşme dosyası: yalnız PDF/JPG/PNG, özel disk (public değil); indirme controller'dan yetkiyle. */
    private function attachContractFile(Contract $contract, UploadedFile $file): void
    {
        $mime = (string) $file->getMimeType();

        if (! in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            throw new DomainException('Sözleşme dosyası PDF, JPG ya da PNG olmalı.');
        }

        if ($file->getSize() > 10 * 1024 * 1024) {
            throw new DomainException('Sözleşme dosyası 10 MB’ı aşamaz.');
        }

        if ($contract->file_path !== null) {
            Storage::disk(self::CONTRACT_DISK)->delete($contract->file_path);
        }

        $path = $file->storeAs('contracts/'.$contract->company_id, $contract->number.'-'.time().'.'.($mime === 'application/pdf' ? 'pdf' : ($mime === 'image/png' ? 'png' : 'jpg')), self::CONTRACT_DISK);
        $contract->forceFill(['file_path' => $path, 'file_name' => mb_substr($file->getClientOriginalName(), 0, 190), 'file_mime' => $mime])->save();
    }

    /** @param  array<string, mixed>  $data */
    private function assertContractData(array $data): void
    {
        if (! isset(Contract::TYPES[$data['type'] ?? ''])) {
            throw new DomainException('Geçersiz sözleşme türü.');
        }

        if (! empty($data['ends_on']) && Carbon::parse((string) $data['ends_on'])->lt(Carbon::parse((string) $data['starts_on']))) {
            throw new DomainException('Bitiş tarihi başlangıçtan önce olamaz.');
        }
    }

    public function contractDocument(User $actor, Contract $contract): Document
    {
        return $this->documents->createContract($actor, $contract->load(['company', 'membership.user', 'membership.profile']));
    }

    // ---- Tahsis / hizmet / belge ----------------------------------------------------------

    /**
     * Tahsisler + demirbaşlar.
     *
     * @return Collection<int, SpaceAssignment>
     */
    public function assignments(Company $company): Collection
    {
        $list = $this->spaces->forCompany($company);
        $list->load(['assets', 'subscription.plan']);

        return $list;
    }

    /** @return Collection<int, Subscription> */
    public function subscriptions(Company $company): Collection
    {
        return $this->subscriptions->forCompany($company);
    }

    /** @return Collection<int, Document> */
    public function documents(Company $company): Collection
    {
        return Document::query()->where('company_id', $company->id)->with(['invoice', 'creator'])->latest('id')->limit(200)->get();
    }

    /** @return Collection<int, Invoice> */
    public function invoices(Company $company): Collection
    {
        return $this->invoices->forCompany($company);
    }

    // ---- Uyarılar, tarihler, aktivite -----------------------------------------------------

    /**
     * Mevcut veriden türeyen uyarılar (kayıt yok): gecikmiş ödeme, açık borç, biten/bitecek sözleşme, biten hizmet,
     * iade bekleyen demirbaş.
     *
     * @return array<int, array{level: string, text: string}>
     */
    public function alerts(UserRole $member): array
    {
        $company = $member->company;
        $f = $this->finance($company);
        $alerts = [];

        if ($f['overdue'] > 0) {
            $alerts[] = ['level' => 'c', 'text' => 'Ödeme gecikmiş: '.Money::format($f['overdue'], $f['currency']).' vadesi geçmiş.'];
        }

        if ($f['remaining'] > 0) {
            $alerts[] = ['level' => 'w', 'text' => 'Ödenmemiş borç bulunuyor: '.Money::format($f['remaining'], $f['currency']).'.'];
        }

        foreach ($this->contracts($company) as $c) {
            $days = $c->daysLeft();

            if ($c->effectiveStatus() === 'expired') {
                $alerts[] = ['level' => 'c', 'text' => 'Sözleşme '.$c->number.' süresi doldu ('.$c->ends_on?->format('d.m.Y').'); yenileyin ya da sonlandırın.'];
            } elseif ($days !== null && $days <= Contract::WARN_DAYS) {
                $alerts[] = ['level' => 'w', 'text' => 'Sözleşme '.$c->number.' '.$days.' gün içinde sona eriyor ('.$c->ends_on?->format('d.m.Y').').'];
            }
        }

        foreach ($this->subscriptions($company) as $s) {
            if ($s->status === 'active' && $s->getAttribute('ends_on') !== null) {
                $days = (int) Carbon::today()->diffInDays($s->ends_on, false);

                if ($days >= 0 && $days <= 15) {
                    $alerts[] = ['level' => 'w', 'text' => 'Hizmet süresi sona eriyor: '.($s->plan->name ?? 'Üyelik').' — '.$s->ends_on->format('d.m.Y').' ('.$days.' gün).'];
                }
            }
        }

        foreach ($this->assignments($company) as $a) {
            $pending = $a->assets->filter(fn (Asset $x) => $x->status === 'assigned');

            if ($a->status !== 'active' && $pending->isNotEmpty()) {
                $alerts[] = ['level' => 'w', 'text' => 'Demirbaş iadesi bekleniyor: '.$pending->pluck('name')->implode(', ').' ('.$a->space?->name.').'];
            } elseif ($a->status === 'active' && $a->ends_on !== null && $pending->isNotEmpty() && (int) Carbon::today()->diffInDays($a->ends_on, false) <= 7 && (int) Carbon::today()->diffInDays($a->ends_on, false) >= 0) {
                $alerts[] = ['level' => 'w', 'text' => 'Tahsis '.$a->space?->name.' '.$a->ends_on->format('d.m.Y').' tarihinde bitiyor; '.$pending->count().' demirbaş teslim alınacak.'];
            }
        }

        return $alerts;
    }

    /**
     * Önemli tarihler (yakınlık işaretiyle).
     *
     * @return array<int, array{label: string, date: ?CarbonInterface, warn: bool}>
     */
    public function dates(UserRole $member): array
    {
        $company = $member->company;
        $f = $this->finance($company);
        $contract = $this->contracts($company)->first(fn (Contract $c) => $c->status === 'active');
        $subscription = $this->subscriptions($company)->first(fn (Subscription $s) => $s->status === 'active');
        $soon = fn (?CarbonInterface $d) => $d !== null && $d->gte(Carbon::today()) && $d->lte(Carbon::today()->addDays(15));
        $lastInvoice = Invoice::query()->where('company_id', $company->id)->whereIn('status', ['issued', 'overdue', 'paid'])->orderByDesc('issued_on')->first();
        $memberSince = $member->profile !== null && $member->profile->getAttribute('member_since') !== null ? $member->profile->member_since : ($member->created_at !== null ? Carbon::parse($member->created_at) : null);

        return [
            ['label' => 'Üyelik başlangıcı', 'date' => $memberSince, 'warn' => false],
            ['label' => 'Sözleşme başlangıcı', 'date' => $contract?->starts_on, 'warn' => false],
            ['label' => 'Sözleşme bitişi', 'date' => $contract?->ends_on, 'warn' => $soon($contract?->ends_on) || ($contract?->daysLeft() !== null && $contract->daysLeft() < 0)],
            ['label' => 'Son fatura', 'date' => $lastInvoice?->issued_on, 'warn' => false],
            ['label' => 'Son ödeme vadesi', 'date' => Invoice::query()->where('company_id', $company->id)->whereIn('status', Invoice::OPEN)->orderBy('due_on')->first()?->due_on, 'warn' => $f['overdue'] > 0],
            ['label' => 'Son tahsilat', 'date' => $f['last_payment'], 'warn' => false],
            ['label' => 'Hizmet başlangıcı', 'date' => $subscription?->starts_on, 'warn' => false],
            ['label' => 'Hizmet bitişi / yenileme', 'date' => $subscription?->ends_on, 'warn' => $soon($subscription?->ends_on)],
            ['label' => 'Son güncelleme', 'date' => $member->profile?->updated_at !== null ? Carbon::parse($member->profile->updated_at) : ($member->updated_at !== null ? Carbon::parse($member->updated_at) : null), 'warn' => false],
        ];
    }

    /**
     * Aktivite geçmişi: şirkete/üyeye ait kayıtların denetim izi (kim · ne · ne zaman) tek zaman çizelgesinde.
     *
     * @return Collection<int, AuditLog>
     */
    public function activity(UserRole $member, int $limit = 120): Collection
    {
        $companyId = $member->company_id;
        $sets = [
            'user_role' => [$member->id],
            'invoice' => Invoice::query()->where('company_id', $companyId)->pluck('id')->all(),
            'payment' => Payment::query()->where('company_id', $companyId)->pluck('id')->all(),
            'subscription' => Subscription::query()->where('company_id', $companyId)->pluck('id')->all(),
            'space_assignment' => SpaceAssignment::query()->where('company_id', $companyId)->pluck('id')->all(),
            'document' => Document::query()->where('company_id', $companyId)->pluck('id')->all(),
            'contract' => Contract::query()->where('company_id', $companyId)->pluck('id')->all(),
            'extra_charge' => ExtraCharge::query()->where('company_id', $companyId)->pluck('id')->all(),
            'company' => [$companyId],
        ];
        $assignmentIds = $sets['space_assignment'];

        $query = AuditLog::query()->with('actor')->where(function ($q) use ($sets, $assignmentIds) {
            foreach ($sets as $type => $ids) {
                if ($ids !== []) {
                    $q->orWhere(fn ($w) => $w->where('entity_type', $type)->whereIn('entity_id', $ids));
                }
            }

            // Demirbaş teslim/iade: iade sonrası demirbaş tahsise bağlı kalmaz; kayıt before/after'daki tahsis id'sinden bulunur.
            if ($assignmentIds !== []) {
                $q->orWhere(fn ($w) => $w->where('entity_type', 'asset')->where(fn ($x) => $x->whereIn('after->space_assignment_id', $assignmentIds)->orWhereIn('before->space_assignment_id', $assignmentIds)));
            }
        });

        return $query->latest('id')->limit($limit)->get();
    }

    /** Aktivite satırı için Türkçe cümle. */
    public static function activityLabel(AuditLog $log): string
    {
        $map = [
            'member.created' => 'Üye oluşturuldu', 'member.updated' => 'Üye bilgileri güncellendi', 'user.suspended' => 'Üyelik askıya alındı', 'user.reactivated' => 'Üyelik yeniden etkinleştirildi',
            'contract.created' => 'Sözleşme oluşturuldu', 'contract.updated' => 'Sözleşme güncellendi', 'contract.ended' => 'Sözleşme sonlandırıldı',
            'subscription.created' => 'Hizmet (üyelik) eklendi', 'subscription.renewed' => 'Hizmet yenilendi', 'subscription.cancelled' => 'Hizmet iptal edildi', 'subscription.expired' => 'Hizmet süresi doldu',
            'space.assigned' => 'Tahsis yapıldı', 'space.assignment_updated' => 'Tahsis güncellendi', 'space.assignment_ended' => 'Tahsis bitirildi',
            'asset.assigned' => 'Demirbaş teslim edildi', 'asset.released' => 'Demirbaş iade alındı',
            'invoice.created' => 'Fatura oluşturuldu', 'invoice.issued' => 'Fatura yayınlandı', 'invoice.overdue' => 'Fatura gecikmeye düştü', 'invoice.cancelled' => 'Fatura iptal edildi', 'invoice.payment_reversed' => 'Tahsilat geri alındı',
            'payment.recorded' => 'Ödeme alındı', 'payment.cancelled' => 'Tahsilat iptal edildi',
            'extra_charge.created' => 'Ek harcama eklendi', 'document.created' => 'Belge oluşturuldu', 'document.updated' => 'Belge düzenlendi', 'document.cancelled' => 'Belge iptal edildi',
            'company.suspended_overdue' => 'Şirket gecikmiş borç nedeniyle askıya alındı',
        ];

        return $map[$log->action] ?? $log->action;
    }

    private static function blank(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
