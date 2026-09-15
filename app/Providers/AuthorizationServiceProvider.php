<?php

namespace App\Providers;

use App\Models\Company;
use App\Models\KycDocument;
use App\Models\User;
use App\Policies\KycDocumentPolicy;
use App\Services\AuthorizationService;
use App\Services\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Gate entegrasyonu: $user->can('kyc.view', $company) ve @can direktifi
 * çalışsın diye. Gate::before KULLANILMAZ — super_admin'e koşulsuz geçiş
 * veren before() kancası, V5 bölüm 3'teki "Super Admin != Root" ilkesini ve
 * tüm JIT mekanizmasını tek satırda devre dışı bırakırdı.
 */
class AuthorizationServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private array $policies = [
        KycDocument::class => KycDocumentPolicy::class,
    ];

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // $result'ın tipi mixed: bir policy bool yerine
        // Illuminate\Auth\Access\Response döndürebilir. ?bool olarak
        // tiplemek o durumda TypeError fırlatırdı.
        Gate::after(function (User $user, string $ability, mixed $result, array $arguments) {
            // Policy zaten karar verdiyse ona dokunma.
            if ($result !== null) {
                return $result;
            }

            /** @var AuthorizationService $authorization */
            $authorization = $this->app->make(AuthorizationService::class);
            /** @var TenantContext $context */
            $context = $this->app->make(TenantContext::class);

            $target = $arguments[0] ?? null;
            $companyId = is_object($target) && isset($target->company_id)
                ? (int) $target->company_id
                : (is_object($target) && $target instanceof Company ? (int) $target->id : null);

            $ctx = [];

            if (($orgId = $context->activeOrganizationId()) !== null) {
                $ctx['organization_id'] = $orgId;
            }

            if ($companyId !== null) {
                $ctx['company_id'] = $companyId;
            }

            return $authorization->can($user, $ability, $ctx);
        });
    }
}
