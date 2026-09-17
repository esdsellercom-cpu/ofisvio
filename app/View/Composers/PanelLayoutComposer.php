<?php

namespace App\View\Composers;

use App\Exceptions\TenantContextException;
use App\Models\Organization;
use App\Services\BookingService;
use App\Services\ContextSwitchService;
use App\Services\TenantContext;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;

/**
 * Panel layout'unun ihtiyaç duyduğu ortak veriyi sağlar: aktif organizasyon,
 * kullanıcı personel mi, organizasyon değiştirebilir mi.
 *
 * Layout'ta servis çağırmak yerine composer: Blade'in yetki/tenant bilgisini
 * tek noktadan alması, her sayfanın bunu ayrı ayrı geçirmesinden güvenlidir
 * (unutulan sayfa menüsüz kalır, yanlış menü göstermez).
 */
class PanelLayoutComposer
{
    public function __construct(
        private readonly Guard $auth,
        private readonly TenantContext $context,
        private readonly ContextSwitchService $switcher,
        private readonly BookingService $bookings,
    ) {}

    public function compose(View $view): void
    {
        // 'panel.*' deseni parçaları da (panel.partials.*, panel.<modül>.partials.*) yakalar;
        // onlar değişkenleri ebeveynden miras alır. Satır başına yeniden hesaplamak N+1 üretiyordu.
        if (str_contains($view->name(), '.partials.')) {
            return;
        }

        $user = $this->auth->user();

        if ($user === null) {
            return;
        }

        $isStaff = $this->context->isInternalStaff($user);
        $active = null;

        // requireOrganization doğrular; üyeliği düşmüş bir kullanıcıya menüde
        // eski organizasyonu göstermek yerine boş bırakılır.
        try {
            $active = $this->context->activeOrganizationId() !== null
                ? $this->context->requireOrganization($user)
                : null;
        } catch (TenantContextException) {
            $active = null;
        }

        $view->with([
            // Resepsiyon masaları: kullanıcının lokasyon kapsamlı aktif rolleri (booking v1).
            // Global personel genel listeden girer; sorgu yalnız lokasyon rolü olabilecekler için.
            'deskLocations' => $isStaff ? new Collection : $this->bookings->deskLocationsFor($user),
            'isStaff' => $isStaff,
            // Personel (global ya da lokasyon) 2FA kurmadan menüde yalnız güvenlik sayfasını görür — "açık kapı" görüntüsü olmasın.
            'twoFactorRequired' => ! $user->hasConfirmedTwoFactor() && $this->context->hasInternalRole($user),
            'unreadNotifications' => (int) $this->context->rememberForRequest("unread:{$user->id}", fn () => $user->unreadNotifications()->count()),
            'activeOrganization' => $active instanceof Organization ? $active : null,
            'canSwitchOrganization' => $isStaff || $this->switcher->enterableOrganizations($user)->count() > 1,
        ]);
    }
}
