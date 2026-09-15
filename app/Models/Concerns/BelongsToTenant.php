<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tenant'a ait her modele eklenir:
 *
 *   class Invoice extends Model
 *   {
 *       use BelongsToTenant;   // varsayılan kolon: company_id
 *   }
 *
 *   class Company extends Model
 *   {
 *       use BelongsToTenant;
 *       protected string $tenantColumn = 'organization_id';
 *   }
 *
 * Ayrıca yeni kayıtlarda tenant kolonunu OTOMATİK doldurur: bir servis
 * company_id set etmeyi unutursa kayıt sahipsiz kalmaz. Zaten set edilmişse
 * dokunulmaz (cross-tenant yazma AuthorizationService'in işi, burada sessizce
 * ezmek hatayı gizlerdi).
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if ($model->tenantColumn() !== 'organization_id' || $model->getAttribute('organization_id') !== null) {
                return;
            }

            /** @var TenantContext $context */
            $context = app(TenantContext::class);

            if (! $context->isSystemMode() && ($orgId = $context->activeOrganizationId()) !== null) {
                $model->setAttribute('organization_id', $orgId);
            }
        });
    }

    /** Bu modelin tenant sınırını taşıyan kolon. */
    public function tenantColumn(): string
    {
        return property_exists($this, 'tenantColumn') ? $this->tenantColumn : 'company_id';
    }

    /**
     * Route model binding, tenant scope'u UYGULAMADAN çözer.
     *
     * NEDEN: SubstituteBindings middleware'i `web` grubundadır ve route
     * middleware'lerinden (auth, tenant, permission) ÖNCE çalışır. Yani model
     * binding, EnsureTenantContext henüz aktif organizasyonu doğrulamadan
     * gerçekleşir. Scope binding sırasında uygulanırsa, oturumun ilk
     * isteğinde context henüz seçilmemiş olduğu için her route 404 döner —
     * kullanıcı organizasyon seçim ekranına yönlendirilmek yerine "bulunamadı"
     * alır.
     *
     * GÜVENLİK: Binding bu pakette güvenlik sınırı DEĞİLDİR. Sınırı
     * EnsurePermission çizer: TenantContext::resolveCompany() şirketin aktif
     * organizasyona ait olduğunu doğrular ve değilse 404 atar. Bu yüzden
     * tenant modeli bağlayan HER route'ta `permission:` middleware'i zorunludur
     * (bkz. routes/BOOTSTRAP.md) ve iç içe route'lar ->scopeBindings() ister.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return static::withoutTenantScope()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    /**
     * İç içe route'larda (`/companies/{company}/kyc/{document}`) çocuk kayıt,
     * ebeveynin ilişkisi üzerinden çözülür — ->scopeBindings() bunu kullanır.
     * Böylece başka bir şirkete ait belge ID'si bu route'a takılmaz.
     */
    public function resolveChildRouteBinding($childType, $value, $field)
    {
        return parent::resolveChildRouteBinding($childType, $value, $field);
    }

    /**
     * Tenant scope'u bilinçli olarak devre dışı bırakır.
     *
     * Her çağrısı bir güvenlik kararıdır ve code review'da gerekçelendirilmelidir.
     * Kodda aranabilir olması kasıtlıdır: `grep -rn withoutTenantScope app/`
     * komutu, tenant savunmasını atlayan tüm yerleri listeler.
     *
     * @return Builder<self>
     */
    public static function withoutTenantScope(): Builder
    {
        return static::withoutGlobalScope(TenantScope::class);
    }
}
