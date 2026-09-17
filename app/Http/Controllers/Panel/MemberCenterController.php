<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ExtraCharge;
use App\Models\MemberProfile;
use App\Models\Payment;
use App\Models\UserRole;
use App\Services\AssetService;
use App\Services\AuthorizationService;
use App\Services\MemberCenterService;
use App\Services\MembershipService;
use App\Services\SpaceService;
use App\Services\SubscriptionService;
use App\Support\Money;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 360° üye merkezi (faz 51) — `/panel/uyeler`. Liste ve profil görünürlüğü CompanyService::visibleTo (üye dizini ile
 * aynı); yazma yetkileri route'ta şirket kapsamıyla: profil/üye membership.manage, sözleşme subscription.manage,
 * ek harcama invoice.issue. Tahsilat, fatura, üyelik, tahsis, demirbaş ve makbuz/gecikme belgesi mevcut rotalara
 * `return` ile gönderilir (tekrar yok). Controller DB'ye gitmez; MemberCenterService birleştirir.
 */
class MemberCenterController extends Controller
{
    public function __construct(
        private readonly MemberCenterService $center,
        private readonly MembershipService $members,
        private readonly SubscriptionService $subscriptions,
        private readonly SpaceService $spaces,
        private readonly AssetService $assets,
        private readonly AuthorizationService $authorization,
    ) {}

    public function index(Request $request): View
    {
        $filters = ['q' => (string) $request->query('q', ''), 'filter' => (string) $request->query('f', '')];
        $data = $this->center->directory($request->user(), $filters);

        return view('panel.members.directory', $data + [
            'q' => $filters['q'], 'filter' => $filters['filter'],
            'filters' => ['' => 'Tümü', 'active' => 'Aktif', 'passive' => 'Pasif', 'debtor' => 'Borçlu', 'credit' => 'Bakiyesi olan', 'contract_soon' => 'Sözleşmesi yaklaşan', 'contract_over' => 'Sözleşmesi biten', 'new' => 'Yeni üyeler (30 gün)'],
            'canCreate' => $this->center->manageableCompanies($request->user())->isNotEmpty(),
        ]);
    }

    public function create(Request $request): View
    {
        $companies = $this->center->manageableCompanies($request->user());
        abort_if($companies->isEmpty(), 403, 'Üye ekleyebileceğiniz şirket yok.');

        return view('panel.members.form', ['member' => null, 'companies' => $companies, 'selectedCompany' => (int) $request->query('company', (string) $companies->first()->id)] + $this->formOptions());
    }

    public function store(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate($this->rules(true) + ['role' => ['nullable', Rule::in(MembershipService::ASSIGNABLE_ROLES)]]);

        try {
            $member = $this->center->createMember($request->user(), $company, $data, $request->file('avatar'));
        } catch (DomainException $e) {
            return back()->withErrors(['email' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.members.show', $member)->with('status', 'Üye oluşturuldu: '.$member->user->name.' ('.$member->profile?->member_no.'). Şifre belirleme bağlantısı e-postaya gönderildi.');
    }

    public function show(Request $request, int $member): View
    {
        $model = $this->center->find($request->user(), $member) ?? abort(404);
        $company = $model->company;
        $user = $request->user();
        $organizationId = $model->company->organization_id;
        $can = fn (string $perm, bool $companyScoped = true) => $this->authorization->can($user, $perm, $companyScoped ? ['organization_id' => $organizationId, 'company_id' => $company->id] : ['organization_id' => $organizationId]);
        $assignments = $this->center->assignments($company);
        $invoices = $this->center->invoices($company);

        return view('panel.members.show', [
            'member' => $model,
            'company' => $company,
            'tab' => in_array($request->query('sekme'), ['ozet', 'finans', 'sozlesme', 'tahsis', 'hizmet', 'belge', 'aktivite'], true) ? (string) $request->query('sekme') : 'ozet',
            'finance' => $this->center->finance($company),
            'ledger' => $this->center->ledger($company),
            'contracts' => $this->center->contracts($company),
            'assignments' => $assignments,
            'subscriptions' => $this->center->subscriptions($company),
            'invoices' => $invoices,
            'openInvoices' => $invoices->filter(fn ($i) => $i->isOpen())->values(),
            'documents' => $this->center->documents($company),
            'alerts' => $this->center->alerts($model),
            'dates' => $this->center->dates($model),
            'activity' => $this->center->activity($model),
            'activityLabel' => fn ($log) => MemberCenterService::activityLabel($log),
            'members' => $this->members->membersOf($company),
            'plans' => $this->subscriptions->plans(true),
            'spaces' => $this->spaces->all($this->authorization->locationIdsWith($user, 'space.manage'))->filter(fn ($s) => $s->inventoryStatus() === 'available')->values(),
            'assignableAssets' => $this->assets->assignable($this->authorization->locationIdsWith($user, 'space.manage')),
            'activeAssignments' => $assignments->filter(fn ($a) => $a->status === 'active')->values(),
            'paymentMethods' => Payment::METHODS,
            'chargeKinds' => ExtraCharge::KINDS,
            'contractTypes' => Contract::TYPES,
            'returnUrl' => route('panel.members.show', $model, false),
            'can' => [
                'manage' => $can('membership.manage'),
                'payment' => $can('payment_allocation.manage', false),
                'invoice' => $can('invoice.issue', false),
                'subscription' => $can('subscription.manage', false),
                'space' => $this->authorization->canAnywhere($user, 'space.manage') || $can('space.manage', false),
                'contract' => $can('subscription.manage'),
            ],
        ]);
    }

    public function edit(Request $request, int $member): View
    {
        $model = $this->center->find($request->user(), $member) ?? abort(404);

        return view('panel.members.form', ['member' => $model, 'companies' => collect([$model->company]), 'selectedCompany' => $model->company_id] + $this->formOptions());
    }

    public function update(Request $request, Company $company, int $member): RedirectResponse
    {
        $model = $this->center->find($request->user(), $member) ?? abort(404);
        abort_if((int) $model->company_id !== (int) $company->id, 404);
        $data = $request->validate($this->rules(false));

        try {
            $this->center->updateMember($request->user(), $model, $data, $request->file('avatar'));
        } catch (DomainException $e) {
            return back()->withErrors(['first_name' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.members.show', $model)->with('status', 'Üye bilgileri güncellendi.');
    }

    // ---- Ek harcama -----------------------------------------------------------------

    public function chargeStore(Request $request, Company $company, int $member): RedirectResponse
    {
        $model = $this->member($request, $company, $member);
        $v = $request->validate([
            'kind' => ['required', Rule::in(array_keys(ExtraCharge::KINDS))], 'description' => ['required', 'string', 'max:200'], 'amount' => ['required', Money::RULE],
            'charged_on' => ['required', 'date'], 'billing' => ['required', 'string', 'max:20', 'regex:/^(none|invoice|link:\d+)$/'], 'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $charge = $this->center->addExtraCharge($request->user(), $company, $model, $v);
        } catch (DomainException $e) {
            return back()->withErrors(['amount' => $e->getMessage()])->withInput($request->except('_modal') + ['_modal' => 'charge']);
        }

        return redirect()->to(route('panel.members.show', $model).'?sekme=finans')->with('status', 'Ek harcama eklendi: '.Money::format($charge->amount, $charge->currency).($charge->invoice_id ? ' — fatura kesildi/bağlandı.' : ' — bakiyeye yansıdı.'));
    }

    // ---- Sözleşmeler -------------------------------------------------------------------

    public function contractStore(Request $request, Company $company, int $member): RedirectResponse
    {
        $model = $this->member($request, $company, $member);
        $v = $request->validate($this->contractRules());

        try {
            $contract = $this->center->createContract($request->user(), $company, $model, $v, $request->file('file'));
        } catch (DomainException $e) {
            return back()->withErrors(['starts_on' => $e->getMessage()])->withInput($request->except('_modal') + ['_modal' => 'contract']);
        }

        return redirect()->to(route('panel.members.show', $model).'?sekme=sozlesme')->with('status', 'Sözleşme oluşturuldu: '.$contract->number);
    }

    public function contractUpdate(Request $request, Company $company, int $member, int $contract): RedirectResponse
    {
        $model = $this->member($request, $company, $member);
        $v = $request->validate($this->contractRules());

        try {
            $this->center->updateContract($request->user(), $this->center->findContract($company, $contract), $v, $request->file('file'));
        } catch (DomainException $e) {
            return back()->withErrors(['starts_on' => $e->getMessage()])->withInput();
        }

        return redirect()->to(route('panel.members.show', $model).'?sekme=sozlesme')->with('status', 'Sözleşme güncellendi.');
    }

    public function contractEnd(Request $request, Company $company, int $member, int $contract): RedirectResponse
    {
        $model = $this->member($request, $company, $member);
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']])['reason'];

        try {
            $this->center->endContract($request->user(), $this->center->findContract($company, $contract), $reason);
        } catch (DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->to(route('panel.members.show', $model).'?sekme=sozlesme')->with('status', 'Sözleşme sonlandırıldı.');
    }

    /** Sözleşme belgesi (belge motoru: şablon, PDF, yazdırma). */
    public function contractDocument(Request $request, Company $company, int $member, int $contract): RedirectResponse
    {
        $this->member($request, $company, $member);

        try {
            $document = $this->center->contractDocument($request->user(), $this->center->findContract($company, $contract));
        } catch (DomainException $e) {
            return back()->withErrors(['contract' => $e->getMessage()]);
        }

        return redirect()->route('panel.collections.documents.show', $document)->with('status', 'Sözleşme belgesi '.$document->number.' oluşturuldu.');
    }

    /** Yüklenen sözleşme dosyası (özel disk) — görünürlük profil ile aynı. */
    public function contractFile(Request $request, int $member, int $contract): StreamedResponse
    {
        $model = $this->center->find($request->user(), $member) ?? abort(404);

        try {
            $record = $this->center->findContract($model->company, $contract);
        } catch (DomainException) {
            abort(404);
        }

        abort_if($record->file_path === null, 404);

        return Storage::disk(MemberCenterService::CONTRACT_DISK)->download($record->file_path, $record->file_name ?? basename($record->file_path));
    }

    // ---- Yardımcılar ----------------------------------------------------------------------

    private function member(Request $request, Company $company, int $member): UserRole
    {
        $model = $this->center->find($request->user(), $member) ?? abort(404);
        abort_if((int) $model->company_id !== (int) $company->id, 404);

        return $model;
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return ['membershipTypes' => MemberProfile::MEMBERSHIP_TYPES, 'contractTypes' => Contract::TYPES, 'roles' => MembershipService::ASSIGNABLE_ROLES];
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(bool $creating): array
    {
        return [
            'first_name' => ['required', 'string', 'max:80'], 'last_name' => ['required', 'string', 'max:80'],
            'email' => $creating ? ['required', 'email', 'max:190'] : ['nullable'],
            'phone' => ['nullable', 'string', 'max:40'], 'title' => ['nullable', 'string', 'max:80'], 'identity_number' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]*$/'],
            'address' => ['nullable', 'string', 'max:300'], 'city' => ['nullable', 'string', 'max:80'], 'country' => ['nullable', 'string', 'max:80'],
            'membership_type' => ['nullable', Rule::in(array_keys(MemberProfile::MEMBERSHIP_TYPES))], 'member_since' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['active', 'suspended'])], 'note' => ['nullable', 'string', 'max:2000'],
            'avatar' => ['nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
            'contract_type' => ['nullable', Rule::in(array_keys(Contract::TYPES))], 'contract_starts_on' => ['nullable', 'date'], 'contract_ends_on' => ['nullable', 'date', 'after_or_equal:contract_starts_on'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function contractRules(): array
    {
        return ['type' => ['required', Rule::in(array_keys(Contract::TYPES))], 'starts_on' => ['required', 'date'], 'ends_on' => ['nullable', 'date'], 'status' => ['nullable', Rule::in(['draft', 'active'])], 'note' => ['nullable', 'string', 'max:2000'], 'file' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png']];
    }
}
