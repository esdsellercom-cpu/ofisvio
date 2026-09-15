<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLeadRequest;
use App\Services\LeadService;
use Illuminate\Http\RedirectResponse;

/**
 * Teklif ve ön rezervasyon talepleri.
 *
 * Controller'da tek bir sorgu yoktur (CLAUDE.md kuralı): doğrulanmış veriyi
 * alır, servisi çağırır, cevabı biçimlendirir. Rıza kanıtını (IP, user-agent)
 * HTTP katmanından okuyup servise PARAMETRE olarak geçer — servis HTTP'ye
 * bağlanmaz, böylece queue'dan da çağrılabilir.
 *
 * DÜRÜSTLÜK NOTU: Tasarımdaki rezervasyon aracı "rezervasyon oluşturuldu"
 * diyordu ama booking modülü henüz yok. Widget ÖN TALEP kaydı oluşturur.
 */
class LeadController extends Controller
{
    public function __construct(private readonly LeadService $leads) {}

    public function store(StoreLeadRequest $request): RedirectResponse
    {
        $lead = $this->leads->capture($request->validated(), [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $anchor = $lead->kind === 'booking' ? 'toplanti' : 'teklif';

        return redirect()
            ->to(route('site.home').'#'.$anchor)
            ->with('lead_sent', $lead->kind)
            ->with('lead_summary', $this->leads->summary($lead));
    }
}
