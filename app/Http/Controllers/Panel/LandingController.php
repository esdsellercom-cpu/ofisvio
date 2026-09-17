<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Giriş sonrası hedef (fortify.home): personel operasyon paneline, müşteri organizasyon
 * dashboard'una (context yoksa seçim ekranına yönlenir). Audit P1-13.
 */
class LandingController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(Request $request): RedirectResponse
    {
        return redirect()->route($this->context->isInternalStaff($request->user()) ? 'panel.operations' : 'panel.dashboard');
    }
}
