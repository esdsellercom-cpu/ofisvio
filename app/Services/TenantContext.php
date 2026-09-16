<?php

namespace App\Services;

use App\Exceptions\TenantContextException;
use App\Models\Company;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;

/**
 * Tenant Context Engine — V5 bölüm 1.
 *
 * Zincir: Authentication -> Active Organization Context -> Membership Check
 *         -> Company Context -> Policy -> Service -> Model Scope
 *
 * TASARIM KARARI: Aktif organizasyon session'da TUTULUR ama session'a
 * GÜVENİLMEZ. Erişim her çağrıda yeniden doğrulanır, çünkü bir kullanıcının
 * üyeliği oturumu açıkken iptal edilebilir (işten ayrılma, askıya alma).
 *
 * ÖNCEKİ DİLİMDE BULUNAN TASARIM HATASI (düzeltildi):
 * requireOrganization() yalnızca organization_members üyeliğine bakıyordu.
 * Ama Ofisvio personeli (system_admin, finance_admin ...) müşteri
 * organizasyonlarının ÜYESİ DEĞİLDİR — olmamalıdır da. Sonuç: iç personel
 * hiçbir tenant context'ine giremiyor, dolayısıyla global yetkilerini hiçbir
 * müşteri kaydı üzerinde kullanamıyordu. Zincirin bir üst katmanı (model
 * scope) yazılmadan bu hata görünmüyordu.
 *
 * Çözüm: iki ayrı giriş yolu, ikisi de doğrulanmış.
 *   - Üyelik yolu   : organization_members kaydı (müşteri kullanıcıları)
 *   - Personel yolu : global kapsamlı aktif bir internal rol (Ofisvio personeli)
 * Personel yolu, ContextSwitchService tarafından context_switch_logs'a
 * ZORUNLU olarak yazılır — "hangi personel hangi müşterinin verisine hangi
 * şapkayla girdi" sorusu ancak böyle cevaplanabilir.
 */
class TenantContext
{
    public const SESSION_KEY = 'tenant.active_organization_id';

    /**
     * Sistem modu: tenant scope'un bilinçli olarak devre dışı bırakıldığı
     * bloklar (queue job'ları, konsol komutları, raporlama). Varsayılan
     * KAPALI ve yalnızca runAsSystem() içinden açılır.
     */
    private bool $systemMode = false;

    /**
     * İstek başına memo (faz 11): isInternalStaff / isActiveMember / organizasyon
     * kaydı bir istekte onlarca kez sorulur (middleware, composer, servisler).
     * Yalnızca HTTP isteği içinde açıktır (PerRequestCaches middleware'i);
     * istek sonunda boşalır. Konsol ve doğrudan çağrılarda kapalıdır.
     *
     * @var array<string, mixed>
     */
    private array $memo = [];

    private bool $memoEnabled = false;

    public function __construct(private readonly Session $session) {}

    public function startRequestCache(): void
    {
        $this->memoEnabled = true;
        $this->memo = [];
    }

    public function stopRequestCache(): void
    {
        $this->memoEnabled = false;
        $this->memo = [];
    }

    /** @param  callable(): mixed  $compute */
    private function remember(string $key, callable $compute): mixed
    {
        if (! $this->memoEnabled) {
            return $compute();
        }

        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $compute();
        }

        return $this->memo[$key];
    }

    public function activeOrganizationId(): ?int
    {
        $id = $this->session->get(self::SESSION_KEY);

        return $id === null ? null : (int) $id;
    }

    /**
     * Aktif organizasyonu döndürür ve erişimi YENİDEN doğrular.
     * Hassas her işlemde bu metod çağrılmalıdır — activeOrganizationId() değil.
     */
    public function requireOrganization(User $user): Organization
    {
        $id = $this->activeOrganizationId();

        if ($id === null) {
            throw TenantContextException::noActiveContext();
        }

        if (! $this->canEnter($user, $id)) {
            // Erişim oturum açıldıktan sonra kaldırılmış olabilir:
            // session'ı temizle ki sonraki istek yeniden seçim yapsın.
            $this->clear();

            throw TenantContextException::notAMember($id);
        }

        return $this->remember("organization:{$id}", fn () => Organization::findOrFail($id));
    }

    /** Bu kullanıcı bu organizasyona girebilir mi? (üyelik VEYA personel yolu) */
    public function canEnter(User $user, int $organizationId): bool
    {
        return $this->isActiveMember($user, $organizationId) || $this->isInternalStaff($user);
    }

    public function isActiveMember(User $user, int $organizationId): bool
    {
        return $this->remember("member:{$user->id}:{$organizationId}", fn () => $user->organizationMemberships()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->exists());
    }

    /**
     * Ofisvio personeli mi? Yalnızca GERÇEKTEN global atanmış (üç kapsam
     * kolonu da NULL) ve rolü 'internal' tipinde olan aktif bir kayıt sayılır.
     * Sehven bir şirkete scope'lanmış personel ataması personel yolunu AÇMAZ —
     * AuthorizationService'teki global kuralıyla birebir aynı mantık.
     */
    public function isInternalStaff(User $user): bool
    {
        return $this->remember("staff:{$user->id}", fn () => DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $user->id)
            ->where('user_roles.status', 'active')
            ->where('roles.type', 'internal')
            ->whereNull('user_roles.company_id')
            ->whereNull('user_roles.organization_id')
            ->whereNull('user_roles.location_id')
            ->exists());
    }

    /**
     * URL'den gelen company_id'yi AKTİF ORGANİZASYONA karşı doğrular.
     *
     * CLAUDE.md kuralı: "URL'den gelen company_id'ye güvenme." İki kademeli
     * savunma: (1) şirket aktif organizasyona ait olmalı, (2) yetki ayrıca
     * AuthorizationService'te user_roles üzerinden kontrol edilir. Bu metod
     * tek başına YETKİ VERMEZ, yalnızca tenant sınırını çizer.
     */
    public function resolveCompany(User $user, int $companyId): Company
    {
        $organization = $this->requireOrganization($user);

        // withoutTenantScope: bu metodun KENDİSİ tenant kontrolünü yapıyor.
        // Global scope'u burada da uygulamak, hatayı 404 yerine "model not
        // found" olarak farklı bir yerden fırlatırdı.
        $company = Company::withoutTenantScope()->find($companyId);

        if ($company === null || (int) $company->organization_id !== (int) $organization->id) {
            // Var olmayan şirket ile başka tenant'ın şirketi AYNI cevabı verir.
            throw TenantContextException::outsideActiveTenant();
        }

        return $company;
    }

    /**
     * AuthorizationService::can() için context dizisi üretir.
     * organization_id daima aktif (doğrulanmış) organizasyondan gelir,
     * asla istekten okunmaz.
     */
    public function toArray(User $user, ?int $companyId = null, ?int $locationId = null): array
    {
        $context = ['organization_id' => $this->requireOrganization($user)->id];

        if ($companyId !== null) {
            $context['company_id'] = $this->resolveCompany($user, $companyId)->id;
        }

        if ($locationId !== null) {
            $context['location_id'] = $locationId;
        }

        return $context;
    }

    /** Yalnızca ContextSwitchService tarafından çağrılmalı. */
    public function setActiveOrganization(int $organizationId): void
    {
        $this->session->put(self::SESSION_KEY, $organizationId);
    }

    public function clear(): void
    {
        $this->session->forget(self::SESSION_KEY);
    }

    // -----------------------------------------------------------------
    // Sistem modu — TenantScope'un bilinçli kaçış kapısı
    // -----------------------------------------------------------------

    public function isSystemMode(): bool
    {
        return $this->systemMode;
    }

    /**
     * Verilen callback'i tenant scope KAPALI çalıştırır.
     *
     * Kullanım yeri dardır ve her çağrısı gözden geçirilmelidir: queue
     * job'ları, konsol komutları, cross-tenant raporlama. HTTP isteği
     * içinde kullanmak, bu paketteki tüm tenant savunmasını o istek için
     * devre dışı bırakmak demektir.
     *
     * İstisna atılsa bile mod geri kapatılır (finally).
     */
    public function runAsSystem(callable $callback): mixed
    {
        $previous = $this->systemMode;
        $this->systemMode = true;

        try {
            return $callback();
        } finally {
            $this->systemMode = $previous;
        }
    }
}
