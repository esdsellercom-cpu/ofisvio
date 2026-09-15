<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReviewKycDocumentRequest;
use App\Http\Requests\UploadKycDocumentRequest;
use App\Models\Company;
use App\Models\KycDocument;
use App\Services\KycService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * KYC controller — referans kalıp.
 *
 * CLAUDE.md kuralı: "Controller'dan doğrudan DB erişimi yasak."
 * Bu dosyada tek bir Eloquent sorgusu yoktur. Controller yalnızca:
 *   1. route model binding'den gelen modeli alır
 *   2. TenantContext'ten DOĞRULANMIŞ context dizisini ister
 *   3. servisi çağırır
 *   4. cevabı biçimlendirir
 *
 * Yetki kontrolü route middleware'indedir (bkz. routes/rbac-example.php).
 * Controller yetki kontrolü YAPMAZ — iki yerde yapılan kontrol, birinin
 * unutulduğu gün sessizce açılır.
 */
class KycController extends Controller
{
    public function __construct(
        private readonly KycService $kyc,
        private readonly TenantContext $context,
    ) {}

    /** GET /companies/{company}/kyc — permission:kyc.view,company */
    public function index(Request $request, Company $company): JsonResponse
    {
        $context = $this->context->toArray($request->user(), $company->id);

        return response()->json([
            'data' => $this->kyc->statusSummary($company),
            'company_status' => $company->status->value,
            'company_status_label' => $company->status->label(),
        ]);
    }

    /** POST /companies/{company}/kyc — permission:kyc.upload,company */
    public function store(UploadKycDocumentRequest $request, Company $company): JsonResponse
    {
        $document = $this->kyc->upload(
            $company,
            $request->user(),
            $request->documentType(),
            $request->file('file'),
        );

        return response()->json([
            'id' => $document->id,
            'type' => $document->type->value,
            'status' => $document->status->value,
        ], 201);
    }

    /**
     * GET /companies/{company}/kyc/{document} — belge İÇERİĞİ
     * permission:kyc.view|kyc.view_document,company,kyc_document,document
     * ->scopeBindings()
     *
     * İki yol tek route'ta: müşteri kendi belgesini JIT'siz açar, personel
     * yalnızca açık bir JIT grant'i varsa açar. Ayrımı KycService::openDocument()
     * yapar; belge–şirket ilişkisini hem scopeBindings hem servis doğrular.
     */
    public function show(Request $request, Company $company, KycDocument $document): StreamedResponse
    {
        $context = $this->context->toArray($request->user(), $company->id);
        $file = $this->kyc->openDocument($request->user(), $document, $context);

        return response()->stream(function () use ($file) {
            fpassthru($file['stream']);
        }, 200, [
            'Content-Type' => $file['mime'],
            // inline değil attachment: tarayıcıda açılan PDF, referrer ve
            // önbellek yoluyla sızabilir.
            'Content-Disposition' => 'attachment; filename="'.addslashes($file['filename']).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }

    /**
     * POST /companies/{company}/kyc/{document}/review
     * permission:kyc.approve,company  (reject/request_more_info için ayrı route)
     */
    public function review(ReviewKycDocumentRequest $request, Company $company, KycDocument $document): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        $document = match ($validated['decision']) {
            'approve' => $this->kyc->approve($user, $document, $validated['note'] ?? null),
            'reject' => $this->kyc->reject($user, $document, $validated['note']),
            'request_more_info' => $this->kyc->requestMoreInfo($user, $document, $validated['note']),
            // ReviewKycDocumentRequest 'decision'ı bu üçe kısıtlar; buraya düşmek
            // validation'ın atlandığı anlamına gelir — sessizce geçme.
            default => throw new InvalidArgumentException('Geçersiz karar: '.json_encode($validated['decision'])),
        };

        return response()->json([
            'id' => $document->id,
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'company_status' => $document->company->refresh()->status->value,
        ]);
    }
}
