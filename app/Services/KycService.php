<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Enums\KycDocumentStatus;
use App\Enums\KycDocumentType;
use App\Models\Company;
use App\Models\KycDocument;
use App\Models\Location;
use App\Models\User;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * KYC modülü — referans kalıp.
 *
 * MATRİSTEKİ KRİTİK AYRIM, bu servisin varlık sebebidir:
 *
 *   kyc.view          (company, JIT yok)  -> müşteri KENDİ belgesini görür
 *   kyc.view_status   (global,  JIT yok)  -> personel yalnızca DURUMU görür
 *   kyc.view_document (global,  JIT var)  -> personel İÇERİĞİ ancak JIT ile açar
 *
 * Yani Ofisvio personeli, müşterinin kimlik belgesine müşterinin kendisinden
 * DAHA ZOR erişir. Bu kasıtlıdır ve KVKK/veri minimizasyonu gereğidir.
 * openDocument() bu ayrımı tek noktada uygular; controller'ın bunu
 * hatırlaması gerekmez.
 *
 * Controller'dan doğrudan DB erişimi yasağı (CLAUDE.md): tüm sorgular burada.
 */
class KycService
{
    /** İzin verilen MIME tipleri. Whitelist — blacklist değil. */
    private const ALLOWED_MIME = [
        'application/pdf', 'image/jpeg', 'image/png',
    ];

    private const MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly JitAccessService $jit,
        private readonly CompanyActivationService $activation,
    ) {}

    // -----------------------------------------------------------------
    // Yükleme (kyc.upload — company scope)
    // -----------------------------------------------------------------

    public function upload(
        Company $company,
        User $uploader,
        KycDocumentType $type,
        UploadedFile $file,
    ): KycDocument {
        if (! in_array($file->getMimeType(), self::ALLOWED_MIME, true)) {
            throw new DomainException('Yalnızca PDF, JPEG ve PNG kabul edilir.');
        }

        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            throw new DomainException('Belge 10 MB sınırını aşıyor.');
        }

        return DB::transaction(function () use ($company, $uploader, $type, $file) {
            // Aynı tipte önceki belge varsa SUPERSEDED yapılır, SİLİNMEZ.
            // Denetim izi için eski belgenin kaydı kalmalıdır.
            KycDocument::where('company_id', $company->id)
                ->where('type', $type->value)
                ->whereNotIn('status', [KycDocumentStatus::SUPERSEDED->value])
                ->get()
                ->each(function (KycDocument $old) {
                    if ($old->status->canTransitionTo(KycDocumentStatus::SUPERSEDED)) {
                        $old->status = KycDocumentStatus::SUPERSEDED;
                        $old->save();
                    }
                });

            // Dosya adı tahmin edilemez olmalı: orijinal ad kullanılırsa
            // storage yolu enumerate edilebilir hale gelir.
            $path = $file->storeAs(
                "kyc/{$company->id}",
                Str::uuid()->toString().'.'.$file->extension(),
                'private'
            );

            $document = KycDocument::create([
                'company_id' => $company->id,
                'type' => $type,
                'status' => KycDocumentStatus::PENDING,
                'original_filename' => $file->getClientOriginalName(),
                'storage_path' => $path,
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'checksum_sha256' => hash_file('sha256', $file->getRealPath()),
                'uploaded_by' => $uploader->id,
            ]);

            // İlk belge yüklendiğinde şirket KYC sürecine girer.
            if ($company->status === CompanyStatus::REGISTERED) {
                $this->activation->beginKyc($company, $uploader);
            }

            return $document;
        });
    }

    // -----------------------------------------------------------------
    // Görüntüleme — matristeki üçlü ayrım burada uygulanır
    // -----------------------------------------------------------------

    /**
     * Belge listesi. İçerik DÖNMEZ, yalnızca metadata.
     *
     * Müşteri kyc.view ile, personel kyc.view_status ile buraya erişir;
     * ikisi de JIT gerektirmez çünkü dönen şey durum bilgisidir.
     *
     * @return array<KycDocument>
     */
    public function listForCompany(User $user, Company $company, array $context): array
    {
        if (! $this->authorization->can($user, 'kyc.view', $context)
            && ! $this->authorization->can($user, 'kyc.view_status', $context)) {
            throw new RuntimeException('kyc listesi için yetki yok');
        }

        return KycDocument::where('company_id', $company->id)
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    /**
     * Belge İÇERİĞİNİ açar. Zincirin en dar kapısı.
     *
     * İki yol var ve ikisi de burada ayrılır:
     *   - Müşteri  : kyc.view (company scope) yeterli, JIT yok
     *   - Personel : kyc.view_document (global) + AÇIK JIT grant zorunlu
     *
     * @return array{stream: resource, mime: string, filename: string}
     */
    public function openDocument(User $user, KycDocument $document, array $context): array
    {
        // İç içe route'ta (/companies/{company}/kyc/{document}) belge ile şirket
        // BAĞIMSIZ parametrelerdir. İlişki doğrulanmazsa, aynı organizasyon
        // içindeki kardeş şirketin belgesi kendi şirketinin id'si ile açılabilir:
        // TenantScope organizasyon sınırını çizer, şirket sınırını değil.
        // Route'lardaki ->scopeBindings() bunu zaten engeller; buradaki kontrol
        // servisi route yapılandırmasından bağımsız olarak güvenli tutar.
        if (isset($context['company_id']) && (int) $document->company_id !== (int) $context['company_id']) {
            throw new RuntimeException('Belge bu şirkete ait değil.');
        }

        $allowed = false;

        // 1) Müşteri yolu: kendi şirketinin belgesi, JIT'siz.
        if ($this->authorization->can($user, 'kyc.view', $context)) {
            $allowed = true;
        }

        // 2) Personel yolu: global izin + kaynak bazlı açık JIT grant.
        if (! $allowed && $this->jit->allows(
            $user,
            'kyc.view_document',
            $context,
            'kyc_document',
            $document->id
        )) {
            $allowed = true;
        }

        if (! $allowed) {
            // kyc.view_status taşıyan personel buraya düşer: durumu görebilir
            // ama içeriği göremez. Mesaj bunu açıkça söyler ki JIT talebine
            // yönlensin.
            throw new RuntimeException(
                'Belge içeriğini açmak için JIT erişim talebi gereklidir (kyc.view_document).'
            );
        }

        $disk = Storage::disk('private');

        if (! $disk->exists($document->storage_path)) {
            throw new RuntimeException('Belge dosyası bulunamadı.');
        }

        return [
            'stream' => $disk->readStream($document->storage_path),
            'mime' => $document->mime_type,
            'filename' => $document->original_filename,
        ];
    }

    // -----------------------------------------------------------------
    // İnceleme (kyc.approve / kyc.reject / kyc.request_more_info — global)
    // -----------------------------------------------------------------

    public function approve(User $reviewer, KycDocument $document, ?string $note = null): KycDocument
    {
        return $this->review($reviewer, $document, KycDocumentStatus::APPROVED, $note);
    }

    public function reject(User $reviewer, KycDocument $document, string $note): KycDocument
    {
        if (trim($note) === '') {
            throw new DomainException('Red gerekçesi zorunludur.');
        }

        return $this->review($reviewer, $document, KycDocumentStatus::REJECTED, $note);
    }

    public function requestMoreInfo(User $reviewer, KycDocument $document, string $note): KycDocument
    {
        if (trim($note) === '') {
            throw new DomainException('Ek bilgi talebinin gerekçesi zorunludur.');
        }

        return $this->review($reviewer, $document, KycDocumentStatus::MORE_INFO_REQUIRED, $note);
    }

    private function review(
        User $reviewer,
        KycDocument $document,
        KycDocumentStatus $target,
        ?string $note,
    ): KycDocument {
        // PENDING -> UNDER_REVIEW ara adımı otomatik atılır: inceleyen kişi
        // kararını verdiği anda belge zaten incelenmiş demektir.
        if ($document->status === KycDocumentStatus::PENDING) {
            $document->status = KycDocumentStatus::UNDER_REVIEW;
        }

        if (! $document->status->canTransitionTo($target)) {
            throw new DomainException(
                "Geçersiz belge durumu geçişi: {$document->status->value} -> {$target->value}"
            );
        }

        return DB::transaction(function () use ($document, $target, $reviewer, $note) {
            $document->status = $target;
            $document->reviewed_by = $reviewer->id;
            $document->reviewed_at = now();
            $document->review_note = $note;
            $document->save();

            $this->syncCompanyStatus($document->company, $reviewer);

            return $document;
        });
    }

    /**
     * Belge durumları değiştikçe şirketin KYC durumunu ilerletir.
     *
     * Şirket KYC_APPROVED olabilmesi için ZORUNLU belge tiplerinin HEPSİ
     * onaylı olmalı. Tek bir belgenin onayı şirketi geçirmez.
     */
    private function syncCompanyStatus(Company $company, User $performedBy): void
    {
        $approvedTypes = KycDocument::where('company_id', $company->id)
            ->where('status', KycDocumentStatus::APPROVED->value)
            ->pluck('type')
            ->map(fn ($t) => $t instanceof KycDocumentType ? $t->value : $t)
            ->unique()
            ->all();

        $requiredTypes = array_map(fn (KycDocumentType $t) => $t->value, KycDocumentType::required());
        $missing = array_diff($requiredTypes, $approvedTypes);

        if ($missing === []) {
            if ($company->status->canTransitionTo(CompanyStatus::KYC_APPROVED)) {
                $this->activation->transitionTo(
                    $company,
                    CompanyStatus::KYC_APPROVED,
                    $performedBy,
                    'Tüm zorunlu KYC belgeleri onaylandı'
                );
            }

            return;
        }

        // Eksik varken şirket KYC_REVIEW'da beklemeli.
        if ($company->status->canTransitionTo(CompanyStatus::KYC_REVIEW)) {
            $this->activation->transitionTo(
                $company,
                CompanyStatus::KYC_REVIEW,
                $performedBy,
                'KYC belgeleri inceleniyor ('.count($missing).' zorunlu belge eksik)'
            );
        }
    }

    /**
     * Şirketin KYC tamamlanma özeti — personelin kyc.view_status ile
     * göreceği şey budur. Belge içeriği YOK.
     *
     * @return array{complete: bool, missing: array<string>, documents: array<array<string, mixed>>}
     */
    public function statusSummary(Company $company): array
    {
        $documents = KycDocument::where('company_id', $company->id)
            ->whereNot('status', KycDocumentStatus::SUPERSEDED->value)
            ->get();

        $approved = $documents
            ->filter(fn (KycDocument $d) => $d->status->countsAsComplete())
            ->map(fn (KycDocument $d) => $d->type->value)
            ->unique()
            ->all();

        $missing = array_values(array_diff(
            array_map(fn (KycDocumentType $t) => $t->value, KycDocumentType::required()),
            $approved
        ));

        return [
            'complete' => $missing === [],
            'missing' => $missing,
            'documents' => $documents->map(fn (KycDocument $d) => [
                'id' => $d->id,
                'type' => $d->type->value,
                'type_label' => $d->type->label(),
                'status' => $d->status->value,
                'status_label' => $d->status->label(),
                'uploaded_at' => $d->created_at?->toIso8601String(),
                'reviewed_at' => $d->reviewed_at?->toIso8601String(),
                'physically_held' => $d->isPhysicallyHeld(),
            ])->all(),
        ];
    }

    // -----------------------------------------------------------------
    // Fiziksel belge (reception — location scope / imha — JIT + dual-control)
    // -----------------------------------------------------------------

    public function logPhysicalReceipt(User $receiver, KycDocument $document, Location $location): KycDocument
    {
        if ($document->physical_received_at !== null) {
            throw new DomainException('Bu belgenin fiziksel teslim kaydı zaten var.');
        }

        $document->physical_received_by = $receiver->id;
        $document->physical_location_id = $location->id;
        $document->physical_received_at = now();
        $document->save();

        return $document;
    }

    /**
     * Fiziksel belge imhası. JIT + dual-control gerektirir.
     *
     * Bu metod yetki KONTROLÜ yapmaz — onu JitAccessService::allows() yapar
     * ve controller/middleware çağırır. Buradaki kontrol iş kuralıdır:
     * elde olmayan belge imha edilemez, iki kez imha edilemez.
     */
    public function destroyPhysicalDocument(User $destroyer, KycDocument $document, string $reason): KycDocument
    {
        if ($document->physical_received_at === null) {
            throw new DomainException('Fiziksel teslim kaydı olmayan belge imha edilemez.');
        }

        if ($document->physical_destroyed_at !== null) {
            throw new DomainException('Bu belge zaten imha edilmiş.');
        }

        if (trim($reason) === '') {
            throw new DomainException('İmha gerekçesi zorunludur.');
        }

        $document->physical_destroyed_at = now();
        $document->physical_destroyed_by = $destroyer->id;
        $document->review_note = trim(($document->review_note ?? '')."\n[İMHA] ".$reason);
        $document->save();

        return $document;
    }
}
