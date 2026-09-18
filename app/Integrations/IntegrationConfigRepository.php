<?php

namespace App\Integrations;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Panelden yazılan entegrasyon ayarları (faz 61b): `integration_settings` (aktif/pasif + secret olmayan alanlar) ve
 * `integration_secrets` (APP_KEY ile şifreli, encryption-at-rest). Öncelik: panelde girilen değer doluysa o, yoksa
 * env/config. Değerler yalnız SecretStore / adaptörlerce okunur; panel maskeli görür; audit yalnız alan ADI yazar.
 * Okuma istek başına tek sorgu (önbellekli); yazma önbelleği düşürür.
 */
class IntegrationConfigRepository
{
    private const CACHE_KEY = 'integrations:overrides:v1';

    /** @var array<string, array{enabled: bool|null, config: array<string, mixed>, secrets: array<string, string>}>|null */
    private ?array $rows = null;

    public function __construct(private readonly CacheRepository $cache, private readonly AuditService $audit) {}

    /** @return array{enabled: bool|null, config: array<string, mixed>, secrets: array<string, string>} */
    public function for(string $provider): array
    {
        return $this->all()[$provider] ?? ['enabled' => null, 'config' => [], 'secrets' => []];
    }

    public function secret(string $provider, string $field): ?string
    {
        $value = $this->for($provider)['secrets'][$field] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Hangi alanlar panelden tanımlı (değer değil). @return list<string> */
    public function definedSecretFields(string $provider): array
    {
        return array_keys($this->for($provider)['secrets']);
    }

    /**
     * Kaydet: secret olmayan alanlar + aktif/pasif; secret'lar yalnız doluysa yazılır (boş = mevcut korunur),
     * "__clear__" siler. Audit: alan adları; değer asla.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $secrets
     */
    public function save(User $actor, string $provider, ?bool $enabled, array $config, array $secrets): void
    {
        $before = $this->for($provider);
        $changedSecrets = [];

        DB::transaction(function () use ($actor, $provider, $enabled, $config, $secrets, &$changedSecrets) {
            DB::table('integration_settings')->updateOrInsert(['provider' => $provider], ['enabled' => $enabled, 'config' => json_encode($config, JSON_UNESCAPED_UNICODE), 'updated_by' => $actor->id, 'updated_at' => now(), 'created_at' => now()]);

            foreach ($secrets as $field => $value) {
                if ($value === '__clear__') {
                    DB::table('integration_secrets')->where('provider', $provider)->where('field', $field)->delete();
                    $changedSecrets[] = $field.' (silindi)';
                } elseif (trim($value) !== '') {
                    DB::table('integration_secrets')->updateOrInsert(['provider' => $provider, 'field' => $field], ['value' => Crypt::encryptString(trim($value)), 'updated_by' => $actor->id, 'updated_at' => now(), 'created_at' => now()]);
                    $changedSecrets[] = $field;
                }
            }
        });

        $this->cache->forget(self::CACHE_KEY);
        $this->rows = null;
        $this->audit->record($actor, 'integration.updated', 'integration', null, ['provider' => $provider, 'enabled' => $before['enabled'], 'config' => $before['config']], ['provider' => $provider, 'enabled' => $enabled, 'config' => $config, 'changed_fields' => $changedSecrets]);
    }

    public function forget(): void
    {
        $this->cache->forget(self::CACHE_KEY);
        $this->rows = null;
    }

    /**
     * Tüm satırlar (bellek → önbellek → DB). Tablo yoksa (ilk kurulum, migration öncesi) boş döner.
     *
     * @return array<string, array{enabled: bool|null, config: array<string, mixed>, secrets: array<string, string>}>
     */
    private function all(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        try {
            $rows = $this->cache->remember(self::CACHE_KEY, 600, function (): array {
                if (! Schema::hasTable('integration_settings')) {
                    return [];
                }

                $out = [];

                foreach (DB::table('integration_settings')->get() as $row) {
                    $config = json_decode((string) $row->config, true);
                    $out[$row->provider] = ['enabled' => $row->enabled === null ? null : (bool) $row->enabled, 'config' => is_array($config) ? $config : [], 'secrets' => []];
                }

                foreach (DB::table('integration_secrets')->get() as $row) {
                    $out[$row->provider] ??= ['enabled' => null, 'config' => [], 'secrets' => []];
                    $out[$row->provider]['secrets'][$row->field] = (string) $row->value; // şifreli saklanır; çözüm okuma anında
                }

                return $out;
            });
        } catch (Throwable) {
            $rows = [];
        }

        foreach ($rows as $provider => &$row) {
            foreach ($row['secrets'] as $field => $encrypted) {
                try {
                    $row['secrets'][$field] = Crypt::decryptString($encrypted);
                } catch (DecryptException) {
                    unset($row['secrets'][$field]); // APP_KEY değişmiş: alan yeniden girilmeli
                }
            }
        }
        unset($row);

        return $this->rows = $rows;
    }
}
