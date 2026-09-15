<?php

namespace App\Http\Controllers\Panel;

use App\Exceptions\TenantContextException;
use App\Http\Controllers\Controller;
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
    ) {}

    public function select(Request $request): View
    {
        $user = $request->user();
        $isStaff = $this->context->isInternalStaff($user);
        $organizations = $this->switcher->enterableOrganizations($user);

        return view('panel.context.select', [
            'organizations' => $organizations,
            'activeId' => $this->context->activeOrganizationId(),
            'pendingCounts' => $isStaff && $organizations->isNotEmpty() ? $this->queue->pendingCounts($user) : [],
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
