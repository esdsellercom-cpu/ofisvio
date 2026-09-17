<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Genel denetim kaydı (master prompt §46): actor · action · entity · before/after ·
 * ip · user agent · zaman · tenant. Secret değerleri asla before/after'a girmez
 * (SECRET_KEYS maskelenir). Yazan servisler: Booking, Settings, Notification,
 * SiteBlock, Room… — her kritik iş kuralı geçişi burada iz bırakır.
 *
 * Request bağımlılığı yalnız IP/UA içindir (TenantContext gibi istisna); konsol/kuyrukta
 * boş kalır.
 */
class AuditService
{
    private const SECRET_KEYS = ['password', 'secret', 'token', 'api_key', 'webhook_secret', 'two_factor_secret'];

    public function __construct(private readonly Request $request, private readonly TenantContext $context) {}

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function record(?User $actor, string $action, string $entityType, ?int $entityId, array $before = [], array $after = [], ?int $organizationId = null): AuditLog
    {
        // Yalnız değişen alanlar (gürültü yok); secret'lar maskeli.
        $changedKeys = array_keys(array_filter($after, fn ($v, $k) => ! array_key_exists($k, $before) || $before[$k] !== $v, ARRAY_FILTER_USE_BOTH));
        $before = $this->mask(array_intersect_key($before, array_flip($changedKeys)));
        $after = $this->mask(array_intersect_key($after, array_flip($changedKeys)));

        return AuditLog::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'organization_id' => $organizationId ?? $this->context->activeOrganizationId(),
            'before' => $before === [] ? null : $before,
            'after' => $after === [] ? null : $after,
            'ip' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 255) ?: null,
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * @param  array{q?: string|null, from?: string|null, to?: string|null, entity?: string|null}  $filters
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function paginate(array $filters, int $perPage = 50): LengthAwarePaginator
    {
        $q = trim((string) ($filters['q'] ?? ''));

        return AuditLog::query()->with('actor')
            ->when($q !== '', fn (Builder $b) => $b->where(fn (Builder $w) => $w->where('action', 'like', "%{$q}%")->orWhere('entity_type', 'like', "%{$q}%")->orWhereHas('actor', fn (Builder $u) => $u->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%"))))
            ->when($filters['entity'] ?? null, fn (Builder $b, $e) => $b->where('entity_type', $e))
            ->when($filters['from'] ?? null, fn (Builder $b, $from) => $b->where('created_at', '>=', $from.' 00:00:00'))
            ->when($filters['to'] ?? null, fn (Builder $b, $to) => $b->where('created_at', '<=', $to.' 23:59:59'))
            ->orderByDesc('created_at')
            ->paginate($perPage)->withQueryString();
    }

    /**
     * Bir kaydın zaman çizelgesi (rezervasyon detayı vb.).
     *
     * @return Collection<int, AuditLog>
     */
    public function trail(string $entityType, int $entityId): Collection
    {
        return AuditLog::query()->with('actor')->where('entity_type', $entityType)->where('entity_id', $entityId)->orderBy('created_at')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mask(array $data): array
    {
        foreach ($data as $k => $v) {
            foreach (self::SECRET_KEYS as $needle) {
                if (str_contains(strtolower((string) $k), $needle)) {
                    $data[$k] = '***';
                }
            }
        }

        return $data;
    }
}
