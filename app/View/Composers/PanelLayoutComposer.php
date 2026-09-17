<?php

namespace App\View\Composers;

use App\Exceptions\TenantContextException;
use App\Models\Organization;
use App\Services\BookingService;
use App\Services\ContextSwitchService;
use App\Services\PanelBadgeService;
use App\Services\TenantContext;
use App\View\Menu\PanelMenu;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;

/**
 * Panel layout'unun ihtiyaç duyduğu ortak veriyi sağlar: aktif organizasyon,
 * kullanıcı personel mi, organizasyon değiştirebilir mi, menü (gruplar +
 * rozetler), tema tercihi.
 *
 * Layout'ta servis çağırmak yerine composer: Blade'in yetki/tenant bilgisini
 * tek noktadan alması, her sayfanın bunu ayrı ayrı geçirmesinden güvenlidir
 * (unutulan sayfa menüsüz kalır, yanlış menü göstermez).
 */
class PanelLayoutComposer
{
    public function __construct(
        private readonly Guard $auth,
        private readonly Gate $gate,
        private readonly TenantContext $context,
        private readonly ContextSwitchService $switcher,
        private readonly BookingService $bookings,
        private readonly PanelBadgeService $badges,
        private readonly PanelMenu $menu,
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

        $activeOrganization = $active instanceof Organization ? $active : null;
        // Personel (global ya da lokasyon) 2FA kurmadan menüde yalnız güvenlik sayfasını görür — "açık kapı" görüntüsü olmasın.
        $twoFactorRequired = ! $user->hasConfirmedTwoFactor() && $this->context->hasInternalRole($user);
        // Resepsiyon masaları: kullanıcının lokasyon kapsamlı aktif rolleri (booking v1).
        // Global personel genel listeden girer; sorgu yalnız lokasyon rolü olabilecekler için.
        $deskLocations = $isStaff ? new Collection : $this->bookings->deskLocationsFor($user);

        // Rozetler: izne göre istenir, tek sorguda sayılır (istek başına bir kez).
        $gate = $this->gate->forUser($user);
        $wanted = ['unread'];

        if (! $twoFactorRequired) {
            $wanted = array_merge($wanted, array_keys(array_filter([
                'bookings_pending' => $gate->allows('booking.view'),
                'leads_new' => $gate->allows('lead.view'),
                'notifications_failed' => $gate->any(['notification.view', 'notification.manage']),
                'kyc_pending' => $isStaff && $activeOrganization !== null && $gate->allows('kyc.view_status'),
                'subscriptions_expiring' => $isStaff && $gate->allows('subscription.view'),
                'invoices_overdue' => $isStaff && $gate->allows('invoice.view'),
                'franchise_new' => $gate->any(['franchise.view', 'franchise.manage']),
            ])));
        }

        $badges = $this->badges->counts($user, $wanted);

        $view->with([
            'deskLocations' => $deskLocations,
            'isStaff' => $isStaff,
            'twoFactorRequired' => $twoFactorRequired,
            'unreadNotifications' => $badges['unread'],
            'panelMenu' => $this->menu->build($user, $twoFactorRequired, $activeOrganization !== null, $deskLocations, $badges),
            'activeOrganization' => $activeOrganization,
            'canSwitchOrganization' => $isStaff || $this->switcher->enterableOrganizations($user)->count() > 1,
            'uiTheme' => $user->ui_theme,
        ]);
    }
}
