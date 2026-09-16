<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyStatusTransition;
use App\Models\ContextSwitchLog;
use App\Models\Scopes\TenantScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;

/**
 * Denetim kaydı (audit.view — super_admin/system_admin, global). SALT OKUNUR.
 *
 * Üç kaynak, üç sekme: JIT erişimleri (jit_access_grants), organizasyon girişleri
 * (context_switch_logs), şirket durum geçişleri (company_status_transitions).
 *
 * withoutTenantScope GEREKÇESİ: denetim kaydı tanımı gereği tüm organizasyonları
 * kapsar; okuyan kişi global audit.view taşır (route'ta zorunlu), yazma yoktur.
 * ArchitectureTest allowlist'inde bu dosya bu gerekçeyle yer alır.
 */
class AuditLogService
{
    public const TYPES = ['jit' => 'JIT erişimleri', 'context' => 'Organizasyon girişleri', 'company' => 'Şirket durum geçişleri'];

    /**
     * @param  array{q?: string|null, from?: string|null, to?: string|null}  $filters
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function jitGrants(array $filters, int $perPage = 50): LengthAwarePaginator
    {
        $q = trim((string) ($filters['q'] ?? ''));

        return DB::table('jit_access_grants as g')
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->join('permissions as p', 'p.id', '=', 'g.permission_id')
            ->leftJoin('users as a', 'a.id', '=', 'g.approved_by')
            ->select(['g.id', 'g.reason', 'g.resource_type', 'g.resource_id', 'g.granted_at', 'g.expires_at', 'g.revoked_at', 'u.name as user_name', 'u.email as user_email', 'p.name as permission', 'a.name as approved_by_name'])
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('u.email', 'like', "%{$q}%")->orWhere('u.name', 'like', "%{$q}%")->orWhere('p.name', 'like', "%{$q}%")->orWhere('g.reason', 'like', "%{$q}%")))
            ->when($filters['from'] ?? null, fn ($b, $from) => $b->where('g.granted_at', '>=', $from.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($b, $to) => $b->where('g.granted_at', '<=', $to.' 23:59:59'))
            ->orderByDesc('g.granted_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array{q?: string|null, from?: string|null, to?: string|null}  $filters
     * @return LengthAwarePaginator<int, ContextSwitchLog>
     */
    public function contextSwitches(array $filters, int $perPage = 50): LengthAwarePaginator
    {
        $q = trim((string) ($filters['q'] ?? ''));

        return ContextSwitchLog::query()
            ->with(['user', 'toOrganization', 'fromOrganization'])
            ->when($q !== '', fn (Builder $b) => $b->where(fn (Builder $w) => $w
                ->whereHas('user', fn (Builder $u) => $u->where('email', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%"))
                ->orWhereHas('toOrganization', fn (Builder $o) => $o->where('name', 'like', "%{$q}%"))
                ->orWhere('ip_address', 'like', "%{$q}%")))
            ->when($filters['from'] ?? null, fn (Builder $b, $from) => $b->where('switched_at', '>=', $from.' 00:00:00'))
            ->when($filters['to'] ?? null, fn (Builder $b, $to) => $b->where('switched_at', '<=', $to.' 23:59:59'))
            ->orderByDesc('switched_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array{q?: string|null, from?: string|null, to?: string|null}  $filters
     * @return LengthAwarePaginator<int, CompanyStatusTransition>
     */
    public function companyTransitions(array $filters, int $perPage = 50): LengthAwarePaginator
    {
        $q = trim((string) ($filters['q'] ?? ''));

        return CompanyStatusTransition::query()
            ->with(['performer', 'company' => fn ($c) => $c->withoutGlobalScope(TenantScope::class)]) // withoutTenantScope eşdeğeri (ilişki sorgusu)
            ->when($q !== '', fn (Builder $b) => $b->where(fn (Builder $w) => $w
                ->whereIn('company_id', Company::withoutTenantScope()->where('legal_name', 'like', "%{$q}%")->select('id'))
                ->orWhereHas('performer', fn (Builder $u) => $u->where('email', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%"))
                ->orWhere('to_status', 'like', "%{$q}%")))
            ->when($filters['from'] ?? null, fn (Builder $b, $from) => $b->where('created_at', '>=', $from.' 00:00:00'))
            ->when($filters['to'] ?? null, fn (Builder $b, $to) => $b->where('created_at', '<=', $to.' 23:59:59'))
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** Özet sayaçlar (denetim ekranı başlığı). @return array{jit: int, context: int, company: int} */
    public function counts(): array
    {
        return [
            'jit' => Schema::hasTable('jit_access_grants') ? (int) DB::table('jit_access_grants')->count() : 0,
            'context' => ContextSwitchLog::query()->count(),
            'company' => CompanyStatusTransition::query()->count(),
        ];
    }
}
