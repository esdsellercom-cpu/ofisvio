<?php

namespace App\View\Composers;

use App\Exceptions\TenantContextException;
use App\Models\Organization;
use App\Services\ContextSwitchService;
use App\Services\TenantContext;
use Illuminate\Contracts\Auth\Guard;
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
    ) {}

    public function compose(View $view): void
    {
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
            'isStaff' => $isStaff,
            'activeOrganization' => $active instanceof Organization ? $active : null,
            'canSwitchOrganization' => $isStaff || $this->switcher->enterableOrganizations($user)->count() > 1,
        ]);
    }
}
