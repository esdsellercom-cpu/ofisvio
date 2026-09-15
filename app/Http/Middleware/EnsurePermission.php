<?php

namespace App\Http\Middleware;

use App\Exceptions\TenantContextException;
use App\Services\JitAccessService;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zincirin 4. ve 5. halkası: Company Context + Policy.
 *
 * Kullanım (route tanımında):
 *   ->middleware('permission:kyc.upload,company')
 *   ->middleware('permission:visitor.view,location')
 *   ->middleware('permission:kyc.view|kyc.view_document,company,kyc_document,document')
 *
 * ALTERNATİF İZİNLER (`|` ile): izinlerden HERHANGİ BİRİ geçerse route açılır.
 *
 * Bu, matristeki gerçek bir ihtiyaçtan doğdu ve bir HATA DÜZELTMESİDİR:
 * KYC belgesinin içeriğine iki ayrı yoldan erişilir — müşteri `kyc.view` ile
 * (company kapsamlı, JIT'siz), personel `kyc.view_document` ile (global, JIT'li).
 * Route tek bir izin isterken müşteri KENDİ belgesini açamıyordu, çünkü
 * müşteri rolleri `kyc.view_document` iznini hiç taşımaz. Tek izinli route,
 * servisteki müşteri yolunu erişilemez hale getiriyordu.
 *
 * ÖNEMLİ: company_id route parametresinden okunur ama TenantContext tarafından
 * aktif organizasyona karşı doğrulanır. Doğrulanmamış hiçbir id yetki
 * kararına girmez. Route model binding tenant scope'u uygulamaz (bkz.
 * BelongsToTenant::resolveRouteBinding) — tenant sınırını burası çizer.
 */
class EnsurePermission
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly JitAccessService $jit,
    ) {}

    public function handle(
        Request $request,
        Closure $next,
        string $permission,
        ?string $scopeParam = null,
        ?string $resourceType = null,
        ?string $resourceParam = null,
    ): Response {
        $user = $request->user();

        if ($user === null) {
            throw TenantContextException::noActiveContext();
        }

        $companyId = null;
        $locationId = null;

        if ($scopeParam === 'company') {
            $companyId = $this->routeId($request, 'company');

            if ($companyId === null) {
                throw TenantContextException::outsideActiveTenant();
            }
        } elseif ($scopeParam === 'location') {
            $locationId = $this->routeId($request, 'location');

            if ($locationId === null) {
                throw TenantContextException::outsideActiveTenant();
            }
        }

        // toArray() company'yi aktif organizasyona karşı doğrular;
        // başka tenant'ın şirketi buradan 404 ile döner.
        $context = $this->context->toArray($user, $companyId, $locationId);

        $resourceId = $resourceParam !== null ? $this->routeId($request, $resourceParam) : null;

        $permissions = array_filter(array_map('trim', explode('|', $permission)));

        foreach ($permissions as $candidate) {
            if ($this->jit->allows($user, $candidate, $context, $resourceType, $resourceId)) {
                return $next($request);
            }
        }

        abort(403, 'Bu işlem için yetkiniz yok: '.implode(' veya ', $permissions));
    }

    /** Route parametresini id'ye çevirir; model binding olmuş olabilir. */
    private function routeId(Request $request, string $name): ?int
    {
        $value = $request->route($name);

        if ($value === null) {
            return null;
        }

        if (is_object($value)) {
            return isset($value->id) ? (int) $value->id : null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
