<?php

namespace App\Services;

use App\Enums\KycDocumentStatus;
use App\Models\KycDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * Personelin KYC inceleme kuyruğu.
 *
 * İKİ SEVİYE:
 *   queueFor()   — aktif organizasyondaki bekleyen belgeler. Tenant scope
 *                  içinde çalışır; personel önce organizasyona girer.
 *   pendingCounts() — organizasyon seçim ekranı için organizasyon başına
 *                  bekleyen belge SAYISI. Bu, tenant sınırını BİLİNÇLİ olarak
 *                  aşar (withoutTenantScope) — gerekçesi:
 *
 *   Personel hangi müşteriye gireceğini seçerken "nerede iş var" bilgisine
 *   ihtiyaç duyar; bunun için her organizasyona tek tek girip çıkmak hem
 *   kullanışsız hem de context_switch_logs'u anlamsız kayıtla doldurur.
 *   Dönen şey yalnızca (organization_id => adet) — belge içeriği, adı, tipi
 *   değil. Çağıran, kullanıcının global kyc.view_status taşıdığını kanıtlamak
 *   zorundadır; taşımıyorsa istisna. tests/Architecture allowlist'inde
 *   bu dosya bu gerekçeyle yer alır.
 */
class KycQueueService
{
    /** Kuyrukta sayılan durumlar: inceleyenin elini bekleyenler. */
    private const QUEUE_STATUSES = [
        KycDocumentStatus::PENDING,
        KycDocumentStatus::UNDER_REVIEW,
    ];

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $context,
    ) {}

    /**
     * Aktif organizasyondaki bekleyen belgeler, en eski önce (FIFO).
     *
     * @return Collection<int, KycDocument>
     */
    public function queueFor(User $user): Collection
    {
        $organizationId = $this->context->requireOrganization($user)->id;
        $this->requireStatusPermission($user, $organizationId);

        return KycDocument::query()
            ->with('company')
            ->whereIn('status', array_map(fn (KycDocumentStatus $s) => $s->value, self::QUEUE_STATUSES))
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Organizasyon başına bekleyen belge adedi — yalnızca personel.
     *
     * @return array<int, int> organization_id => adet
     */
    public function pendingCounts(User $user): array
    {
        if (! $this->context->isInternalStaff($user)) {
            throw new RuntimeException('Organizasyonlar arası KYC sayımı yalnızca personel içindir.');
        }

        $this->requireStatusPermission($user, null);

        // withoutTenantScope: yukarıdaki sınıf başlığındaki gerekçeyle. Yalnızca
        // sayım; belge satırı dönmez.
        return KycDocument::withoutTenantScope()
            ->join('companies', 'companies.id', '=', 'kyc_documents.company_id')
            ->whereIn('kyc_documents.status', array_map(fn (KycDocumentStatus $s) => $s->value, self::QUEUE_STATUSES))
            ->whereNull('companies.deleted_at')
            ->groupBy('companies.organization_id')
            ->selectRaw('companies.organization_id as organization_id, count(*) as pending')
            ->pluck('pending', 'organization_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    private function requireStatusPermission(User $user, ?int $organizationId): void
    {
        $context = $organizationId !== null ? ['organization_id' => $organizationId] : [];

        if (! $this->authorization->can($user, 'kyc.view_status', $context)) {
            throw new RuntimeException('KYC kuyruğu için kyc.view_status yetkisi gerekir.');
        }
    }
}
