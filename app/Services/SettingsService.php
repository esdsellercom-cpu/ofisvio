<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use App\Settings\SettingsRegistry;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\Cache;

/**
 * Ayar merkezi (master prompt §31–33). Okuma: kapsam kalıtımı
 *   location > company > organization > installation > registry varsayılanı
 * Yazma: tanım doğrulaması (tip + kapsam), audit, önbellek geçersizleme.
 *
 * Önbellek: tüm ayarlar tek anahtarda (küçük tablo), her yazımda düşer —
 * "global cache flush" değil, yalnız ayar önbelleği.
 */
class SettingsService
{
    public const CACHE_KEY = 'settings:all';

    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array{organization_id?: int|null, company_id?: int|null, location_id?: int|null}  $context
     */
    public function get(string $key, array $context = []): mixed
    {
        $def = SettingsRegistry::definition($key);
        $all = $this->all();

        foreach ([['location', $context['location_id'] ?? null], ['company', $context['company_id'] ?? null], ['organization', $context['organization_id'] ?? null], ['installation', null]] as [$scope, $id]) {
            if ($scope !== 'installation' && $id === null) {
                continue;
            }

            $row = $all["{$key}|{$scope}|".($id ?? '')] ?? null;

            if ($row !== null) {
                return $this->cast($def['type'], $row);
            }
        }

        return $def['default'];
    }

    public function bool(string $key, array $context = []): bool
    {
        return (bool) $this->get($key, $context);
    }

    public function int(string $key, array $context = []): int
    {
        return (int) $this->get($key, $context);
    }

    public function string(string $key, array $context = []): string
    {
        return (string) $this->get($key, $context);
    }

    /**
     * Bir kapsamda saklanan ham değer (form için); yoksa null (kalıtım gösterimi ayrı).
     */
    public function stored(string $key, string $scope = 'installation', ?int $scopeId = null): mixed
    {
        $row = $this->all()["{$key}|{$scope}|".($scopeId ?? '')] ?? null;

        return $row === null ? null : $this->cast(SettingsRegistry::definition($key)['type'], $row);
    }

    /**
     * Ayarı yazar; null/'' = kapsamdaki kaydı sil (kalıtıma dön). Audit + önbellek.
     */
    public function set(?User $actor, string $key, mixed $value, string $scope = 'installation', ?int $scopeId = null): void
    {
        Money::forgetCurrency(); // general.currency değişebilir; gösterim memosu düşer
        $def = SettingsRegistry::definition($key);

        if (! in_array($scope, $def['scopes'], true)) {
            throw new DomainException("'{$key}' ayarı {$scope} kapsamında tanımlanamaz.");
        }

        if ($scope !== 'installation' && $scopeId === null) {
            throw new DomainException('Kapsam kimliği gerekli.');
        }

        $before = $this->stored($key, $scope, $scopeId);
        $query = Setting::query()->where('key', $key)->where('scope', $scope)->where('scope_id', $scopeId);

        if ($value === null || $value === '') {
            $query->delete();
        } else {
            $value = $this->cast($def['type'], $value);
            Setting::query()->updateOrCreate(['key' => $key, 'scope' => $scope, 'scope_id' => $scopeId], ['value' => ['v' => $value], 'updated_by' => $actor?->id]);
        }

        Cache::forget(self::CACHE_KEY);

        if ($before !== ($value === '' ? null : $value)) {
            // entity: setting:<anahtar>@<kapsam>[#id] — hangi ayar, hangi kapsamda; önce/sonra yalnız değer.
            $this->audit->record($actor, 'settings.changed', 'setting:'.$key.'@'.$scope.($scopeId ? "#{$scopeId}" : ''), null, ['value' => $before], ['value' => $value === '' ? null : $value]);
        }
    }

    /**
     * Panel formu: grup -> tanımlar + saklanan değer + etkin değer.
     *
     * @return array<string, array<string, array{key: string, def: array<string, mixed>, stored: mixed, effective: mixed}>>
     */
    public function formData(string $scope = 'installation', ?int $scopeId = null): array
    {
        $out = [];

        foreach (SettingsRegistry::definitions() as $key => $def) {
            if (! in_array($scope, $def['scopes'], true)) {
                continue;
            }

            // effective: üst kapsamdan miras alınacak değer (lokasyon formu için kurulum değeri).
            $out[$def['group']][$key] = ['key' => $key, 'def' => $def, 'stored' => $this->stored($key, $scope, $scopeId), 'effective' => $this->get($key)];
        }

        return $out;
    }

    /** @return array<string, mixed> key|scope|id => ham değer */
    private function all(): array
    {
        $cached = Cache::remember(self::CACHE_KEY, 3600, fn () => Setting::query()->get()
            ->mapWithKeys(fn (Setting $s) => ["{$s->key}|{$s->scope}|".($s->scope_id ?? '') => $s->value['v'] ?? null])
            ->all());

        return $cached;
    }

    private function cast(string $type, mixed $value): mixed
    {
        return match ($type) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $value,
            default => (string) $value,
        };
    }
}
