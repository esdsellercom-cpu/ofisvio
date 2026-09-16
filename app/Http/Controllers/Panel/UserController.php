<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserRole;
use App\Services\UserAdminService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Kullanıcı yönetimi (faz 29). Tümü user.manage (global; system_admin/super_admin).
 * Şifre girilmez/gösterilmez: davet ve yeniden gönderme sıfırlama bağlantısıdır.
 * {userRole} route parametresi kullanıcıya ait olmalı (aksi 404) — bkz. roleOf().
 */
class UserController extends Controller
{
    public function __construct(private readonly UserAdminService $users) {}

    public function index(Request $request): View
    {
        $q = (string) $request->query('q', '');

        return view('panel.users.index', ['users' => $this->users->paginate($q), 'q' => $q]);
    }

    public function create(): View
    {
        return view('panel.users.form', ['roles' => $this->users->internalRoles()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'role' => ['required', 'string', Rule::in($this->users->internalRoles()->pluck('name')->all())],
        ]);

        try {
            $result = $this->users->inviteStaff($request->user(), $validated);
        } catch (DomainException $e) {
            return back()->withErrors(['role' => $e->getMessage()])->withInput();
        }

        $message = $result['created']
            ? ($result['invited'] ? 'Personel oluşturuldu; şifre belirleme bağlantısı e-postayla gönderildi.' : 'Personel oluşturuldu; e-posta gönderilemedi — "Daveti yeniden gönder" ile deneyin.')
            : 'Kullanıcı zaten vardı; rol atandı.';

        return redirect()->route('panel.users.show', $result['user'])->with('status', $message);
    }

    public function show(User $user): View
    {
        return view('panel.users.show', [
            'user' => $this->users->find($user->id),
            'roles' => $this->users->internalRoles(),
        ]);
    }

    public function assignRole(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate(['role' => ['required', 'string', Rule::in($this->users->internalRoles()->pluck('name')->all())]]);

        try {
            $this->users->assignInternalRole($request->user(), $user, $validated['role']);
        } catch (DomainException $e) {
            return back()->withErrors(['role' => $e->getMessage()]);
        }

        return redirect()->route('panel.users.show', $user)->with('status', 'Rol atandı.');
    }

    public function suspendRole(Request $request, User $user, UserRole $userRole): RedirectResponse
    {
        try {
            $this->users->suspendRole($request->user(), $this->roleOf($user, $userRole));
        } catch (DomainException $e) {
            return back()->withErrors(['role' => $e->getMessage()]);
        }

        return redirect()->route('panel.users.show', $user)->with('status', 'Rol askıya alındı.');
    }

    public function reactivateRole(User $user, UserRole $userRole): RedirectResponse
    {
        $this->users->reactivateRole($this->roleOf($user, $userRole));

        return redirect()->route('panel.users.show', $user)->with('status', 'Rol yeniden etkinleştirildi.');
    }

    public function resendInvite(User $user): RedirectResponse
    {
        $sent = $this->users->resendInvite($user);

        return redirect()->route('panel.users.show', $user)->with('status', $sent ? 'Şifre belirleme bağlantısı gönderildi.' : 'E-posta gönderilemedi.');
    }

    /** Rol kaydı bu kullanıcıya ait değilse 404 — id tahmini ile başkasının rolü değiştirilemez. */
    private function roleOf(User $user, UserRole $userRole): UserRole
    {
        abort_if((int) $userRole->user_id !== (int) $user->id, 404);

        return $userRole;
    }
}
