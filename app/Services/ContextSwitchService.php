<?php

namespace App\Services;

use App\Exceptions\TenantContextException;
use App\Models\ContextSwitchLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aktif organizasyon değiştirme — V5 bölüm 1.2.
 *
 * Context değişimi audit edilebilir bir olaydır: kim, ne zaman, hangi
 * organizasyondan hangisine, hangi yoldan (üyelik / personel), hangi IP'den
 * geçti. Bu kayıt olmadan "bu işlemi hangi şapkayla yaptı" sorusu cevaplanamaz.
 *
 * İKİ GİRİŞ YOLU (bkz. TenantContext):
 *   - Üyelik yolu  : organization_members kaydı olan müşteri kullanıcısı
 *   - Personel yolu: global internal rol taşıyan Ofisvio personeli — hiçbir
 *                    organizasyonun üyesi değildir, ama her birine girebilir.
 *                    Bu giriş ZORUNLU olarak entry_path='staff' ile loglanır.
 */
class ContextSwitchService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Session $session,
    ) {}

    public function switchTo(User $user, int $organizationId, ?string $ipAddress = null): Organization
    {
        // 1) Giriş hakkı — üyelik VEYA personel yolu; ikisi de doğrulanmış.
        // Var olmayan organizasyon ile girilemeyen organizasyon aynı cevabı
        // verir (enumeration savunması).
        $isMember = $this->context->isActiveMember($user, $organizationId);
        $organization = Organization::find($organizationId);

        if ($organization === null || (! $isMember && ! $this->context->isInternalStaff($user))) {
            throw TenantContextException::notAMember($organizationId);
        }

        $from = $this->context->activeOrganizationId();

        // Aynı organizasyona geçiş: no-op, log şişirmeye gerek yok.
        if ($from === $organizationId) {
            return $organization;
        }

        DB::transaction(function () use ($user, $from, $organizationId, $isMember, $ipAddress) {
            ContextSwitchLog::create([
                'user_id' => $user->id,
                'from_organization_id' => $from,
                'to_organization_id' => $organizationId,
                'entry_path' => $isMember ? ContextSwitchLog::PATH_MEMBERSHIP : ContextSwitchLog::PATH_STAFF,
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

    /**
     * Kullanıcının girebileceği organizasyonlar — seçim ekranı bunu listeler.
     *
     * Personel için TÜM organizasyonlar döner: personel yolu tanım gereği
     * organizasyon-bağımsızdır. Bu liste yalnızca ad/slug taşır, tenant
     * verisi değil; Organization modeli TenantScope taşımaz.
     *
     * @return Collection<int, Organization>
     */
    public function enterableOrganizations(User $user): Collection
    {
        if ($this->context->isInternalStaff($user)) {
            return Organization::query()->orderBy('name')->get();
        }

        $ids = $user->organizationMemberships()
            ->where('status', 'active')
            ->pluck('organization_id');

        return Organization::query()->whereIn('id', $ids)->orderBy('name')->get();
    }
}
