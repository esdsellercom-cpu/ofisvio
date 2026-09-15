<?php

namespace App\Http\Controllers\Panel;

use App\Enums\KycDocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\RequestJitAccessRequest;
use App\Http\Requests\UploadKycDocumentRequest;
use App\Models\Company;
use App\Models\KycDocument;
use App\Services\AuthorizationService;
use App\Services\JitAccessService;
use App\Services\KycQueueService;
use App\Services\KycService;
use App\Services\TenantContext;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * KYC ekranları (F4 müşteri, F5 personel). Aynı sayfa iki şapkaya hizmet
 * eder; hangi bölümlerin görüneceğini AuthorizationService söyler, controller
 * karar vermez. Yetki route'ta (bkz. routes/panel.php):
 *
 *   show      kyc.view | kyc.view_status      (company)
 *   upload    kyc.upload                      (company)
 *   download  kyc.view | kyc.view_document    (company, kyc_document)  <- JIT
 *   approve   kyc.approve                     (company)
 *   reject    kyc.reject                      (company)
 *   moreInfo  kyc.request_more_info           (company)
 *   jit       kyc.view_status                 (company)  -> grant açar
 *   queue     kyc.view_status                 (global)
 *
 * Controller'da DB sorgusu yok; tüm okuma/yazma servistedir.
 */
class KycController extends Controller
{
    public function __construct(
        private readonly KycService $kyc,
        private readonly KycQueueService $queue,
        private readonly JitAccessService $jit,
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $context,
    ) {}

    public function show(Request $request, Company $company): View
    {
        $user = $request->user();
        $context = $this->context->toArray($user, $company->id);
        $documents = collect($this->kyc->listForCompany($user, $company, $context));

        $can = [
            'upload' => $this->authorization->can($user, 'kyc.upload', $context),
            'review' => $this->authorization->can($user, 'kyc.approve', $context),
            // Müşteri yolu: kendi belgesini JIT'siz açar.
            'open_as_owner' => $this->authorization->can($user, 'kyc.view', $context),
            // Personel yolu: rolde kyc.view_document var mı (JIT talep edebilir mi)?
            'request_jit' => $this->authorization->can($user, 'kyc.view_document', $context),
        ];

        // Personel için belge başına açık grant durumu — "İndir" mi "JIT iste" mi?
        $grants = [];
        if (! $can['open_as_owner'] && $can['request_jit']) {
            foreach ($documents as $document) {
                $grants[$document->id] = $this->jit->hasActiveGrant($user, 'kyc.view_document', 'kyc_document', $document->id);
            }
        }

        // Belge tipi başına "geçerli" (SUPERSEDED olmayan en yeni) belge.
        $latestByType = [];
        foreach ($documents as $document) {
            $key = $document->type->value;
            if (! isset($latestByType[$key]) && $document->status->value !== 'SUPERSEDED') {
                $latestByType[$key] = $document;
            }
        }

        return view('panel.kyc.show', [
            'company' => $company,
            'summary' => $this->kyc->statusSummary($company),
            'documents' => $documents,
            'latestByType' => $latestByType,
            'types' => KycDocumentType::cases(),
            'can' => $can,
            'grants' => $grants,
            'defaultTtl' => JitAccessService::DEFAULT_TTL_MINUTES,
            'maxTtl' => JitAccessService::MAX_TTL_MINUTES,
        ]);
    }

    public function upload(UploadKycDocumentRequest $request, Company $company): RedirectResponse
    {
        try {
            $document = $this->kyc->upload($company, $request->user(), $request->documentType(), $request->file('file'));
        } catch (DomainException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()
            ->route('panel.companies.kyc.show', $company)
            ->with('status', $document->type->label().' yüklendi; inceleme aynı iş günü içinde yapılır.');
    }

    public function download(Request $request, Company $company, KycDocument $kycDocument): StreamedResponse|RedirectResponse
    {
        $context = $this->context->toArray($request->user(), $company->id);

        try {
            $file = $this->kyc->openDocument($request->user(), $kycDocument, $context);
        } catch (RuntimeException $e) {
            return redirect()->route('panel.companies.kyc.show', $company)->withErrors(['document' => $e->getMessage()]);
        }

        return response()->stream(function () use ($file) {
            fpassthru($file['stream']);
        }, 200, [
            'Content-Type' => $file['mime'],
            // attachment: tarayıcıda açılan PDF referrer ve önbellek yoluyla sızabilir.
            'Content-Disposition' => 'attachment; filename="'.addslashes($file['filename']).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }

    public function approve(Request $request, Company $company, KycDocument $kycDocument): RedirectResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return $this->decide($company, fn () => $this->kyc->approve($request->user(), $kycDocument, $validated['note'] ?? null), 'Belge onaylandı.');
    }

    public function reject(Request $request, Company $company, KycDocument $kycDocument): RedirectResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:2000']]);

        return $this->decide($company, fn () => $this->kyc->reject($request->user(), $kycDocument, $validated['note']), 'Belge reddedildi; müşteri gerekçeyi görecek.');
    }

    public function moreInfo(Request $request, Company $company, KycDocument $kycDocument): RedirectResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:2000']]);

        return $this->decide($company, fn () => $this->kyc->requestMoreInfo($request->user(), $kycDocument, $validated['note']), 'Ek bilgi istendi.');
    }

    /**
     * Personelin belge içeriği için JIT talebi. Grant açılırsa aynı sayfada
     * "İndir" görünür; açılmazsa sebep gösterilir.
     */
    public function requestJit(RequestJitAccessRequest $request, Company $company, KycDocument $kycDocument): RedirectResponse
    {
        $user = $request->user();
        $context = $this->context->toArray($user, $company->id);
        $validated = $request->validated();

        $grantId = $this->jit->grant(
            $user,
            'kyc.view_document',
            $context,
            'kyc_document',
            $kycDocument->id,
            $validated['reason'],
            null,
            (int) $validated['ttl_minutes'],
        );

        if ($grantId === null) {
            return back()->withErrors(['reason' => 'JIT erişimi açılamadı: rolünüz kyc.view_document taşımıyor ya da gerekçe boş.']);
        }

        return redirect()
            ->route('panel.companies.kyc.show', $company)
            ->with('status', 'Belge içeriğine '.$validated['ttl_minutes'].' dakikalık erişim açıldı. Erişim denetim kaydına yazıldı.');
    }

    public function queue(Request $request): View
    {
        return view('panel.kyc.queue', [
            'documents' => $this->queue->queueFor($request->user()),
        ]);
    }

    /** @param  callable(): KycDocument  $decision */
    private function decide(Company $company, callable $decision, string $message): RedirectResponse
    {
        try {
            $decision();
        } catch (DomainException $e) {
            return back()->withErrors(['note' => $e->getMessage()]);
        }

        return redirect()->route('panel.companies.kyc.show', $company)->with('status', $message);
    }
}
