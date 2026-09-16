<?php

namespace App\Http\Controllers\Panel;

use App\Exceptions\TenantContextException;
use App\Http\Controllers\Controller;
use App\Services\AuthorizationService;
use App\Services\ContextSwitchService;
use App\Services\KycQueueService;
use App\Services\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Organizasyon seçimi (F2). auth ister, tenant middleware'i TAŞIMAZ —
 * aksi halde context'i olmayan kullanıcı buraya yönlendirilip yine buradan
 * yönlendirilirdi.
 */
class ContextController extends Controller
{
    public function __construct(
        private readonly ContextSwitchService $switcher,
        private readonly TenantContext $context,
        private readonly KycQueueService $queue,
        private readonly AuthorizationService $authorization,
    ) {}

    public function select(Request $request): View
    {
        $user = $request->user();
        $organizations = $this->switcher->enterableOrganizations($user);

        // Bekleyen KYC sütunu yalnızca kyc.view_status taşıyan personele:
        // finance_admin gibi iç roller personeldir ama bu sayımı göremez.
        $showsQueue = $this->context->isInternalStaff($user)
            && $this->authorization->can($user, 'kyc.view_status');

        return view('panel.context.select', [
            'organizations' => $organizations,
            'activeId' => $this->context->activeOrganizationId(),
            'showsQueue' => $showsQueue,
            'pendingCounts' => $showsQueue && $organizations->isNotEmpty() ? $this->queue->pendingCounts($user) : [],
        ]);
    }

    public function switch(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'integer'],
        ]);

        try {
            $organization = $this->switcher->switchTo(
                $request->user(),
                (int) $validated['organization_id'],
                $request->ip(),
            );
        } catch (TenantContextException $e) {
            // Üye olunmayan / var olmayan organizasyon: aynı ekranda hata,
            // id sızdırmadan.
            return back()->withErrors(['organization_id' => 'Bu organizasyona giriş yetkiniz yok.']);
        }

        return redirect()
            ->route('panel.dashboard')
            ->with('status', $organization->name.' organizasyonuna geçildi.');
    }
}
