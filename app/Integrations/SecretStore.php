<?php

namespace App\Integrations;

use RuntimeException;

/**
 * Secret yönetimi (faz 5 + faz 61b). Kaynaklar, öncelik sırasıyla:
 *   1. Panelden girilen değer (Entegrasyon merkezi; `integration_secrets` APP_KEY ile şifreli, `integration_settings`),
 *   2. env → config('integrations').
 * Değerler loglanmaz, API/panel cevabına girmez (masked() dışında), yalnız Gateway ve sağlayıcı adaptörleri okur.
 * Sağlayıcı açık ama secret boşsa fail-closed: RuntimeException.
 */
final class SecretStore
{
    public function __construct(private readonly IntegrationConfigRepository $overrides) {}

    /**
     * Sağlayıcı yapılandırması: config + panel üst yazımı (aktif/pasif, secret'lar, base_url/model gibi alanlar).
     *
     * @return array<string, mixed>
     */
    public function provider(string $provider): array
    {
        $config = config("integrations.providers.{$provider}");

        if (! is_array($config)) {
            throw new RuntimeException("Bilinmeyen sağlayıcı: {$provider}");
        }

        $override = $this->overrides->for($provider);

        if ($override['enabled'] !== null) {
            $config['enabled'] = $override['enabled'];
        }

        foreach ($override['config'] as $key => $value) {
            if ($key !== 'secrets' && $key !== 'enabled' && is_scalar($value) && trim((string) $value) !== '') {
                $config[$key] = $value;
            }
        }

        foreach ($override['secrets'] as $field => $value) {
            if ($field === 'webhook_secret') {
                $config['webhook_secret'] = $value;
            } elseif (is_array($config['secrets'] ?? null) && array_key_exists($field, $config['secrets'])) {
                $config['secrets'][$field] = $value;
            } elseif (array_key_exists($field, $config)) {
                $config[$field] = $value; // secret olarak saklanan ama sağlayıcıda üst düzey alan (örn. pagespeed api_key)
            }
        }

        return $config;
    }

    /** Secret olmayan sağlayıcı alanı (base_url, model, timeout…): panel üst yazımı > env/config. */
    public function config(string $provider, string $key, mixed $default = null): mixed
    {
        $value = $this->provider($provider)[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
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
            throw new RuntimeException("{$provider} için '{$key}' secret'ı tanımlı değil (env ya da Entegrasyon merkezi).");
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
     * Panel/doctor görünümü: secret değeri yerine yalnız "var/yok" ve kaynağı.
     *
     * @return array<string, string>
     */
    public function masked(string $provider): array
    {
        $out = [];
        $fromPanel = $this->overrides->definedSecretFields($provider);

        foreach (array_keys((array) ($this->provider($provider)['secrets'] ?? [])) as $key) {
            $out[(string) $key] = $this->has($provider, (string) $key) ? '•••• (tanımlı'.(in_array($key, $fromPanel, true) ? ', panel' : ', env').')' : '— (yok)';
        }

        return $out;
    }
}
