<?php

namespace App\Http\Middleware;

use App\Exceptions\TenantContextException;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zincirin 2. ve 3. halkası: Active Organization Context + Membership Check.
 *
 * Kimlik doğrulanmış her istekte aktif organizasyonun VAR ve GEÇERLİ olduğunu
 * garanti eder. Buradan sonraki hiçbir katman "context var mı" diye sormak
 * zorunda kalmaz.
 */
class EnsureTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw TenantContextException::noActiveContext();
        }

        // Context hiç seçilmemişse ve kullanıcı TEK bir organizasyona üyeyse
        // otomatik seç — çok üyeliklilerde seçim ekranı zorunlu (409).
        if ($this->context->activeOrganizationId() === null) {
            $memberships = $user->organizationMemberships()
                ->where('status', 'active')
                ->pluck('organization_id');

            if ($memberships->count() !== 1) {
                throw TenantContextException::noActiveContext();
            }

            $this->context->setActiveOrganization((int) $memberships->first());
        }

        // Üyeliği yeniden doğrula (session'a güvenilmez).
        $this->context->requireOrganization($user);

        return $next($request);
    }
}
