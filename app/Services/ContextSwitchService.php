<?php

namespace App\Services;

use App\Exceptions\TenantContextException;
use App\Models\ContextSwitchLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;

/**
 * Aktif organizasyon değiştirme — V5 bölüm 1.2.
 *
 * Context değişimi audit edilebilir bir olaydır: kim, ne zaman, hangi
 * organizasyondan hangisine, hangi IP'den geçti. Bu kayıt olmadan
 * "bu işlemi hangi şapkayla yaptı" sorusu cevaplanamaz.
 */
class ContextSwitchService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Session $session,
    ) {}

    public function switchTo(User $user, int $organizationId, ?string $ipAddress = null): Organization
    {
        // 1) Üyelik doğrulaması — hedef organizasyona gerçekten üye mi?
        if (! $this->context->isActiveMember($user, $organizationId)) {
            throw TenantContextException::notAMember($organizationId);
        }

        $organization = Organization::findOrFail($organizationId);
        $from = $this->context->activeOrganizationId();

        // Aynı organizasyona geçiş: no-op, log şişirmeye gerek yok.
        if ($from === $organizationId) {
            return $organization;
        }

        DB::transaction(function () use ($user, $from, $organizationId, $ipAddress) {
            ContextSwitchLog::create([
                'user_id' => $user->id,
                'from_organization_id' => $from,
                'to_organization_id' => $organizationId,
                'ip_address' => $ipAddress,
                'switched_at' => now(),
            ]);

            $this->context->setActiveOrganization($organizationId);
        });

        // Session fixation savunması: context değişimi ayrıcalık sınırı
        // değiştirdiği için session id yenilenir. Eski id ile ele geçirilmiş
        // bir oturum yeni context'i devralamaz.
        $this->session->migrate(true);
        $this->context->setActiveOrganization($organizationId);

        return $organization;
    }
}
