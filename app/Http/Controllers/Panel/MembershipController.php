<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\InviteMemberRequest;
use App\Models\Company;
use App\Models\UserRole;
use App\Services\MembershipService;
use App\Services\TenantContext;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Şirket üyelikleri (faz 7). Yetki route'ta: permission:membership.manage,company
 * ({userRole} scopeBindings ile {company}->userRoles() üzerinden çözülür).
 */
class MembershipController extends Controller
{
    public function __construct(
        private readonly MembershipService $memberships,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request, Company $company): View
    {
        $this->context->toArray($request->user(), $company->id);

        return view('panel.members.index', [
            'company' => $company,
            'members' => $this->memberships->membersOf($company),
            'roles' => MembershipService::ASSIGNABLE_ROLES,
        ]);
    }

    public function store(InviteMemberRequest $request, Company $company): RedirectResponse
    {
        try {
            $result = $this->memberships->invite($request->user(), $company, $request->validated());
        } catch (DomainException $e) {
            return back()->withErrors(['role' => $e->getMessage()])->withInput();
        }

        $message = $result['user']->name.' — '.__('roles.'.$result['role']->role->name).' olarak eklendi.';
        $message .= $result['invited']
            ? ' Şifre belirleme bağlantısı e-postasına gönderildi.'
            : ' Hesabı zaten vardı; mevcut şifresiyle giriş yapabilir.';

        return redirect()->route('panel.companies.members.index', $company)->with('status', $message);
    }

    public function suspend(Request $request, Company $company, UserRole $userRole): RedirectResponse
    {
        try {
            $this->memberships->suspend($request->user(), $company, $userRole);
        } catch (DomainException $e) {
            return back()->withErrors(['member' => $e->getMessage()]);
        }

        return redirect()->route('panel.companies.members.index', $company)->with('status', 'Rol askıya alındı.');
    }

    public function reactivate(Request $request, Company $company, UserRole $userRole): RedirectResponse
    {
        try {
            $this->memberships->reactivate($company, $userRole);
        } catch (DomainException $e) {
            return back()->withErrors(['member' => $e->getMessage()]);
        }

        return redirect()->route('panel.companies.members.index', $company)->with('status', 'Rol yeniden etkinleştirildi.');
    }
}
