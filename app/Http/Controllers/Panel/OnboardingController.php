<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\OpenOrganizationRequest;
use App\Services\OrganizationOnboardingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Yeni müşteri organizasyonu (personel). Yetki route'ta: permission:user.manage
 * (global) — tenant middleware'i YOK, organizasyon henüz yok.
 */
class OnboardingController extends Controller
{
    public function __construct(private readonly OrganizationOnboardingService $onboarding) {}

    public function create(): View
    {
        return view('panel.onboarding.create');
    }

    public function store(OpenOrganizationRequest $request): RedirectResponse
    {
        $result = $this->onboarding->open($request->user(), $request->validated());

        $message = $result['organization']->name.' açıldı. ';
        $message .= $result['invited']
            ? $result['owner']->email.' adresine şifre belirleme bağlantısı gönderildi.'
            : $result['owner']->email.' zaten kayıtlıydı; mevcut hesap sahip olarak eklendi.';

        return redirect()->route('panel.context.select')->with('status', $message);
    }
}
