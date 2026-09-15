<?php

namespace App\Models\Scopes;

use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use LogicException;

/**
 * Zincirin SON halkası: Model Scope.
 *
 * Buraya kadarki katmanlar (middleware, policy, service) doğru yazılmış koda
 * güvenir. Bu katman güvenmez: bir controller yanlışlıkla Invoice::all()
 * çağırsa bile, başka tenant'ın kaydı sorgudan dönmez. Derinlemesine savunmanın
 * son satırıdır.
 *
 * FAIL-CLOSED: Aktif tenant context yoksa sorgu HİÇBİR kayıt döndürmez
 * (1 = 0). Bunun alternatifi "scope'u uygulama, hepsini döndür" olurdu ve
 * o davranış, context kurulmamış her yolu sessizce cross-tenant sızıntıya
 * çevirirdi — global scope'ların klasik tuzağı budur.
 *
 * Bilinçli sistem işlemleri (queue, konsol, cross-tenant rapor) için
 * TenantContext::runAsSystem() veya Model::withoutTenantScope() kullanılır;
 * ikisi de kodda AÇIKÇA görünür, sessizce devreye girmez.
 *
 * SINIR: Bu scope ORGANIZASYON sınırını çizer, şirket bazlı yetkiyi değil.
 * Aynı organizasyon içindeki kardeş şirketleri birbirinden ayırmak
 * AuthorizationService'in işidir. İki katman farklı soruları cevaplar:
 *   TenantScope           -> "bu kayıt bu tenant'a ait mi?"
 *   AuthorizationService  -> "bu kullanıcının bu kayda yetkisi var mı?"
 */
/**
 * @implements Scope<Model>
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        /** @var TenantContext $context */
        $context = app(TenantContext::class);

        if ($context->isSystemMode()) {
            return;
        }

        // Scope yalnızca BelongsToTenant tarafından eklenir; başka bir yoldan
        // eklenmişse kolonu tahmin etmek yerine yüksek sesle dur.
        if (! method_exists($model, 'tenantColumn')) {
            throw new LogicException($model::class.' TenantScope taşıyor ama BelongsToTenant kullanmıyor.');
        }

        $organizationId = $context->activeOrganizationId();
        $table = $model->getTable();
        $column = $model->tenantColumn();

        if ($organizationId === null) {
            // Fail-closed. whereRaw yerine imkânsız bir eşitlik kullanıyoruz ki
            // sorgu geçerli SQL kalsın ve union/count gibi yapılarda patlamasın.
            $builder->whereRaw('1 = 0');

            return;
        }

        if ($column === 'organization_id') {
            // getQuery(): kolon adı burada dinamiktir (scope her tenant modeline
            // uygulanır), Larastan ise Eloquent where()'de model özelliği ister.
            // Eloquent Builder::where zaten string kolonu aynen buraya iletir.
            $builder->getQuery()->where("{$table}.organization_id", $organizationId);

            return;
        }

        // company_id taşıyan modeller: aktif organizasyona ait şirketlerle sınırla.
        // Alt sorgu kasıtlı: companies tablosuna join atmak, çağıranın kendi
        // join'leriyle çakışabilir ve select * davranışını bozabilir.
        $builder->whereIn("{$table}.{$column}", function ($query) use ($organizationId) {
            $query->select('id')
                ->from('companies')
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at');
        });
    }
}
