<?php

namespace App\Http\Middleware;

use App\Services\AuthorizationService;
use App\Services\ContentCache;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * İstek başına önbellekler (faz 11 — Performance Foundation).
 *
 * Yetki çözümlemesi ve tenant üyelik/personel sorguları bir istekte onlarca kez
 * tekrarlanır; burada istek başında memo açılır, yanıt üretildikten sonra
 * boşaltılır — bir sonraki istek (testte de) taze başlar. İstek dışında
 * (konsol, doğrudan servis çağrısı) memo kapalıdır.
 */
class PerRequestCaches
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $context,
        private readonly ContentCache $contentCache,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->authorization->startRequestCache();
        $this->context->startRequestCache();
        $this->contentCache->startRequestCache();

        try {
            return $next($request);
        } finally {
            $this->authorization->stopRequestCache();
            $this->context->stopRequestCache();
            $this->contentCache->stopRequestCache();
        }
    }
}
