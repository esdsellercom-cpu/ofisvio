<?php

namespace App\Integrations;

use RuntimeException;

/**
 * Secret yönetimi (faz 5). Tek kaynak env → config('integrations'). Değerler:
 *   - veritabanına yazılmaz, loglanmaz, API/panel cevabına girmez (masked() dışında),
 *   - yalnız Gateway ve sağlayıcı adaptörleri okur.
 * Sağlayıcı açık ama secret boşsa fail-closed: RuntimeException.
 */
final class SecretStore
{
    /** @return array<string, mixed> */
    private function provider(string $provider): array
    {
        $config = config("integrations.providers.{$provider}");

        if (! is_array($config)) {
            throw new RuntimeException("Bilinmeyen sağlayıcı: {$provider}");
        }

        return $config;
    }

    public function enabled(string $provider): bool
    {
        return (bool) ($this->provider($provider)['enabled'] ?? false);
    }

    public function has(string $provider, string $key): bool
    {
        $value = $this->provider($provider)['secrets'][$key] ?? null;

        return is_string($value) && trim($value) !== '';
    }

    /** Değer doğrudan döner; çağıran onu ASLA loglamaz/yanıta koymaz. */
    public function get(string $provider, string $key): string
    {
        if (! $this->has($provider, $key)) {
            throw new RuntimeException("{$provider} için '{$key}' secret'ı tanımlı değil (env).");
        }

        return (string) $this->provider($provider)['secrets'][$key];
    }

    public function webhookSecret(string $provider): ?string
    {
        $value = $this->provider($provider)['webhook_secret'] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** Sağlayıcının eksik secret adları (doctor / panel). @return array<int, string> */
    public function missing(string $provider): array
    {
        $missing = [];

        foreach (array_keys((array) ($this->provider($provider)['secrets'] ?? [])) as $key) {
            if (! $this->has($provider, (string) $key)) {
                $missing[] = (string) $key;
            }
        }

        return $missing;
    }

    /**
     * Panel/doctor görünümü: secret değeri yerine yalnız "var/yok" ve uzunluk sınıfı.
     *
     * @return array<string, string>
     */
    public function masked(string $provider): array
    {
        $out = [];

        foreach (array_keys((array) ($this->provider($provider)['secrets'] ?? [])) as $key) {
            $out[(string) $key] = $this->has($provider, (string) $key) ? '•••• (tanımlı)' : '— (yok)';
        }

        return $out;
    }
}
