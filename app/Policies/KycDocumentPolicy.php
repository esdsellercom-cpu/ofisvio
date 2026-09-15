<?php

namespace App\Policies;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\JitAccessService;
use App\Services\TenantContext;

/**
 * Policy katmanı — Gate/@can ile kullanılmak üzere.
 *
 * Route middleware'i (EnsurePermission) HTTP sınırında karar verir; bu policy
 * ise Blade şablonlarında ve servis içi kararlarda aynı mantığı tekrarlamadan
 * kullanmak içindir. İkisi de AYNI AuthorizationService'i çağırır — kural tek
 * yerde tanımlı kalır.
 */
class KycDocumentPolicy
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly JitAccessService $jit,
        private readonly TenantContext $context,
    ) {}

    private function contextFor(User $user, KycDocument $document): array
    {
        return [
            'organization_id' => $this->context->activeOrganizationId(),
            'company_id' => $document->company_id,
        ];
    }

    /** Metadata/durum görme. */
    public function view(User $user, KycDocument $document): bool
    {
        $context = $this->contextFor($user, $document);

        return $this->authorization->can($user, 'kyc.view', $context)
            || $this->authorization->can($user, 'kyc.view_status', $context);
    }

    /** Belge İÇERİĞİNİ açma — personel için JIT zorunlu. */
    public function viewContent(User $user, KycDocument $document): bool
    {
        $context = $this->contextFor($user, $document);

        if ($this->authorization->can($user, 'kyc.view', $context)) {
            return true; // müşteri kendi belgesi
        }

        return $this->jit->allows($user, 'kyc.view_document', $context, 'kyc_document', $document->id);
    }

    public function download(User $user, KycDocument $document): bool
    {
        return $this->jit->allows(
            $user,
            'kyc.download',
            $this->contextFor($user, $document),
            'kyc_document',
            $document->id
        );
    }

    public function review(User $user, KycDocument $document): bool
    {
        return $this->authorization->can($user, 'kyc.approve', $this->contextFor($user, $document));
    }

    public function destroyPhysical(User $user, KycDocument $document): bool
    {
        return $this->jit->allows(
            $user,
            'kyc.physical_document.destroy',
            $this->contextFor($user, $document),
            'kyc_document',
            $document->id
        );
    }
}
