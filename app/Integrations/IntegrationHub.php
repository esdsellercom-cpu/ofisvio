<?php

namespace App\Integrations;

use App\Integrations\Google\SearchConsoleClient;
use App\Integrations\Google\ServiceAccountAuth;
use App\Models\IntegrationLog;
use App\Models\IntegrationSyncState;
use App\Models\User;
use App\Models\WebhookEvent;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Entegrasyon merkezi (faz 61b): kayıt defteri (şema) + panel üst yazımı (IntegrationConfigRepository) + bağlantı
 * testi + durum (🟢 bağlı · 🟡 yapılandırılmadı · 🔴 hata · ⚪ pasif). Akış: Bağlan → bilgileri gir → test et →
 * aktifleştir. Çekirdek (mail/storage) alanları çalışma zamanı config'ine uygulanır (applyRuntime).
 */
class IntegrationHub
{
    public const STATES = ['connected' => '🟢 Bağlı', 'unconfigured' => '🟡 Yapılandırılmadı', 'error' => '🔴 Hata', 'disabled' => '⚪ Pasif', 'link' => '↗ Başka ekranda'];

    public function __construct(
        private readonly IntegrationConfigRepository $repository,
        private readonly SecretStore $secrets,
        private readonly ConnectionTester $tester,
        private readonly Gateway $gateway,
    ) {}

    /**
     * Liste: kategori → entegrasyonlar (durum, son bağlantı, son senkron, hata).
     *
     * @return array<string, array{label: string, lead: string, items: list<array<string, mixed>>}>
     */
    public function overview(): array
    {
        $out = [];

        foreach (IntegrationRegistry::CATEGORIES as $category => $meta) {
            $out[$category] = ['label' => $meta['label'], 'lead' => $meta['lead'], 'items' => []];
        }

        foreach (IntegrationRegistry::definitions() as $key => $def) {
            $out[$def['category']]['items'][] = $this->status($key) + ['key' => $key, 'label' => $def['label'], 'kind' => $def['kind'], 'description' => $def['description'], 'link' => $def['link'] ?? null];
        }

        return $out;
    }

    /**
     * Tek entegrasyon durumu.
     *
     * @return array{state: string, state_label: string, enabled: bool, missing: list<string>, last_ok_at: string|null, last_error_at: string|null, last_error: string|null, last_sync_at: string|null, source: string}
     */
    public function status(string $key): array
    {
        $def = IntegrationRegistry::definition($key) ?? throw new DomainException('Bilinmeyen entegrasyon: '.$key);
        $missing = [];
        $enabled = true;
        $source = 'env';

        if ($def['kind'] === 'link') {
            return ['state' => 'link', 'state_label' => self::STATES['link'], 'enabled' => true, 'missing' => [], 'last_ok_at' => null, 'last_error_at' => null, 'last_error' => null, 'last_sync_at' => null, 'source' => '—'];
        }

        if ($def['kind'] === 'gateway') {
            $enabled = $this->secrets->enabled($key);
            $missing = $this->secrets->missing($key);

            foreach ($def['fields'] as $field) {
                if (($field['required'] ?? false) && $field['type'] !== 'secret' && trim((string) $this->value($key, $field)) === '') {
                    $missing[] = $field['key'];
                }
            }

            $source = $this->repository->for($key)['secrets'] !== [] || $this->repository->for($key)['config'] !== [] ? 'panel' : 'env';
        } else {
            foreach ($def['fields'] as $field) {
                if (($field['required'] ?? false) && trim((string) $this->value($key, $field)) === '') {
                    $missing[] = $field['key'];
                }
            }

            $override = $this->repository->for($key);
            $source = $override['secrets'] !== [] || $override['config'] !== [] ? 'panel' : 'env';
            $enabled = $key === 'storage' ? (string) config('filesystems.default') === 's3' : ! in_array((string) config('mail.default'), ['log', 'array'], true);
        }

        $lastOk = IntegrationLog::query()->where(fn ($q) => $q->where('provider', $key)->orWhere('provider', 'health:'.$key))->where('ok', true)->latest('id')->first();
        $lastFail = IntegrationLog::query()->where(fn ($q) => $q->where('provider', $key)->orWhere('provider', 'health:'.$key))->where('ok', false)->latest('id')->first();
        $sync = IntegrationSyncState::query()->where('provider', $key)->latest('last_success_at')->first();
        $recentError = $lastFail !== null && ($lastOk === null || $lastFail->id > $lastOk->id);

        $state = match (true) {
            ! $enabled => 'disabled',
            $missing !== [] => 'unconfigured',
            $recentError => 'error',
            default => 'connected',
        };

        return [
            'state' => $state,
            'state_label' => self::STATES[$state],
            'enabled' => $enabled,
            'missing' => array_values(array_unique($missing)),
            'last_ok_at' => $lastOk?->created_at?->toIso8601String(),
            'last_error_at' => $lastFail?->created_at?->toIso8601String(),
            'last_error' => $recentError ? (string) $lastFail->error : null,
            'last_sync_at' => $sync?->last_success_at?->toIso8601String(),
            'source' => $source,
        ];
    }

    /**
     * Form verisi: alan başına {value|masked, from_env, defined}. Secret değeri asla dönmez.
     *
     * @return list<array<string, mixed>>
     */
    public function formFields(string $key): array
    {
        $def = IntegrationRegistry::definition($key) ?? throw new DomainException('Bilinmeyen entegrasyon: '.$key);
        $override = $this->repository->for($key);
        $out = [];

        foreach ($def['fields'] as $field) {
            $isSecret = $field['type'] === 'secret';
            $envValue = $this->envValue($key, $field);
            $panelValue = $isSecret ? ($override['secrets'][$field['key']] ?? null) : ($override['config'][$field['key']] ?? null);
            $defined = ($panelValue !== null && $panelValue !== '') || $envValue !== '';
            $out[] = $field + [
                'defined' => $defined,
                'from_env' => $envValue !== '' && ($panelValue === null || $panelValue === ''),
                'value' => $isSecret ? '' : (string) ($panelValue !== null && $panelValue !== '' ? $panelValue : $envValue),
                'masked' => $isSecret ? ($defined ? '••••••••••••' : '') : null,
            ];
        }

        return $out;
    }

    /**
     * Kaydet: secret olmayan alanlar config'e, secret'lar şifreli; boş secret = mevcut korunur.
     *
     * @param  array<string, mixed>  $input
     */
    public function save(User $actor, string $key, array $input, ?bool $enabled, bool $canManageSecrets): void
    {
        $def = IntegrationRegistry::definition($key) ?? throw new DomainException('Bilinmeyen entegrasyon: '.$key);

        if ($def['kind'] === 'link') {
            throw new DomainException('Bu entegrasyon başka ekranda yönetilir.');
        }

        $config = $this->repository->for($key)['config'];
        $secrets = [];

        foreach ($def['fields'] as $field) {
            $raw = $input[$field['key']] ?? null;

            if ($field['type'] === 'secret') {
                if (is_string($raw) && trim($raw) !== '') {
                    if (! $canManageSecrets) {
                        throw new DomainException('Secret girmek için secrets.manage izni gerekir.');
                    }

                    $secrets[$field['key']] = trim($raw);
                }

                if (! empty($input['clear_'.$field['key']]) && $canManageSecrets) {
                    $secrets[$field['key']] = '__clear__';
                }

                continue;
            }

            $value = trim((string) ($raw ?? ''));

            if ($value === '') {
                unset($config[$field['key']]);

                continue;
            }

            $config[$field['key']] = match ($field['type']) {
                'int' => (string) max(0, (int) $value),
                'url' => preg_match('#^https://[^\s]+$#', $value) === 1 ? $value : throw new DomainException($field['label'].' https:// ile başlayan geçerli bir adres olmalı.'),
                'select' => isset($field['options'][$value]) ? $value : throw new DomainException($field['label'].' için geçersiz seçim.'),
                default => mb_substr($value, 0, 300),
            };
        }

        $this->repository->save($actor, $key, $enabled, $config, $secrets);
    }

    /**
     * Bağlantı testi: entegrasyona özel (AI: model listesi, Google: belirteç/mülkler, mail: SMTP, storage: yazma),
     * sonuç kullanıcı dostu; log'a secret girmez.
     *
     * @return array{level: string, note: string}
     */
    public function test(string $key): array
    {
        $def = IntegrationRegistry::definition($key) ?? throw new DomainException('Bilinmeyen entegrasyon: '.$key);
        $started = hrtime(true);

        try {
            $result = match ($def['test'] ?? null) {
                'ai' => $this->testAi(),
                'google' => $this->testGoogle($key),
                'storage' => $this->testStorage(),
                'mail' => $this->tester->test('mail'),
                'gateway' => $this->tester->test($key),
                default => ['level' => 'warn', 'note' => 'Bu entegrasyon için test yok.'],
            };
        } catch (Throwable $e) {
            $result = ['level' => 'fail', 'note' => self::friendly($e->getMessage())];
        }

        $this->tester->log($key, $result, (int) ((hrtime(true) - $started) / 1e6));

        return $result;
    }

    /** Çekirdek alanların çalışma zamanı uygulaması (mail / S3): boot'ta; DB yoksa sessizce atlar. */
    /**
     * Ayar ekranındaki son istekler: sağlayıcının kendi istekleri + bağlantı testleri.
     *
     * @return Collection<int, IntegrationLog>
     */
    public function recentLogs(string $key): Collection
    {
        return IntegrationLog::query()->where(fn ($q) => $q->where('provider', $key)->orWhere('provider', 'health:'.$key))->latest('id')->limit(8)->get();
    }

    /** @return LengthAwarePaginator<int, IntegrationLog> */
    public function logs(string $provider, bool $onlyErrors): LengthAwarePaginator
    {
        return IntegrationLog::query()
            ->when($provider !== '', fn ($q) => $q->where('provider', $provider))
            ->when($onlyErrors, fn ($q) => $q->where('ok', false))
            ->latest('id')->paginate(50)->withQueryString();
    }

    /** @return list<string> */
    public function logProviders(): array
    {
        return IntegrationLog::query()->select('provider')->distinct()->orderBy('provider')->pluck('provider')->all();
    }

    /** @return array{total_24h: int, errors_24h: int, avg_ms_24h: int} */
    public function logStats(): array
    {
        $day = IntegrationLog::query()->where('created_at', '>=', now()->subDay());

        return [
            'total_24h' => (clone $day)->count(),
            'errors_24h' => (clone $day)->where('ok', false)->count(),
            'avg_ms_24h' => (int) round((float) (clone $day)->avg('duration_ms')),
        ];
    }

    /** @return Collection<int, WebhookEvent> */
    public function incomingEvents(): Collection
    {
        return WebhookEvent::query()->latest('id')->limit(10)->get();
    }

    public function applyRuntime(): void
    {
        try {
            $mail = $this->repository->for('mail');
            $map = ['host' => 'mail.mailers.smtp.host', 'port' => 'mail.mailers.smtp.port', 'username' => 'mail.mailers.smtp.username', 'encryption' => 'mail.mailers.smtp.encryption', 'from_name' => 'mail.from.name', 'from_address' => 'mail.from.address'];

            foreach ($map as $field => $configKey) {
                if (isset($mail['config'][$field]) && trim((string) $mail['config'][$field]) !== '') {
                    config([$configKey => $field === 'port' ? (int) $mail['config'][$field] : ($field === 'encryption' && $mail['config'][$field] === 'none' ? null : $mail['config'][$field])]);
                }
            }

            if (isset($mail['secrets']['password'])) {
                config(['mail.mailers.smtp.password' => $mail['secrets']['password']]);
            }

            if ($mail['config'] !== [] && in_array((string) config('mail.default'), ['log', 'array'], true) && isset($mail['config']['host'])) {
                config(['mail.default' => 'smtp']); // panelden SMTP girildiyse log sürücüsü yerine gerçek gönderim
            }

            $storage = $this->repository->for('storage');
            $s3 = ['key' => 'filesystems.disks.s3.key', 'bucket' => 'filesystems.disks.s3.bucket', 'region' => 'filesystems.disks.s3.region', 'endpoint' => 'filesystems.disks.s3.endpoint', 'url' => 'filesystems.disks.s3.url'];

            foreach ($s3 as $field => $configKey) {
                if (isset($storage['config'][$field]) && trim((string) $storage['config'][$field]) !== '') {
                    config([$configKey => $storage['config'][$field]]);
                }
            }

            if (isset($storage['secrets']['secret'])) {
                config(['filesystems.disks.s3.secret' => $storage['secrets']['secret']]);
            }
        } catch (Throwable) {
            // Boot sırasında DB/anahtar sorunu uygulamayı düşürmez; panel durumu "yapılandırılmadı" gösterir.
        }
    }

    // ---- yardımcılar ----------------------------------------------------------------------------

    /** @param  array<string, mixed>  $field */
    private function value(string $key, array $field): string
    {
        $override = $this->repository->for($key);
        $panel = $field['type'] === 'secret' ? ($override['secrets'][$field['key']] ?? '') : ($override['config'][$field['key']] ?? '');

        return trim((string) $panel) !== '' ? (string) $panel : $this->envValue($key, $field);
    }

    /** env/config'teki değer (secret ise yalnız var/yok için). @param  array<string, mixed>  $field */
    private function envValue(string $key, array $field): string
    {
        $def = IntegrationRegistry::definition($key) ?? [];

        if (($def['kind'] ?? '') === 'gateway') {
            $config = config('integrations.providers.'.$key, []);
            $value = $field['type'] === 'secret' ? ($config['secrets'][$field['key']] ?? ($field['key'] === 'webhook_secret' ? ($config['webhook_secret'] ?? '') : ($config[$field['key']] ?? ''))) : ($config[$field['key']] ?? '');

            return is_scalar($value) ? (string) $value : '';
        }

        $map = $key === 'mail'
            ? ['host' => 'mail.mailers.smtp.host', 'port' => 'mail.mailers.smtp.port', 'username' => 'mail.mailers.smtp.username', 'password' => 'mail.mailers.smtp.password', 'encryption' => 'mail.mailers.smtp.encryption', 'from_name' => 'mail.from.name', 'from_address' => 'mail.from.address']
            : ['key' => 'filesystems.disks.s3.key', 'secret' => 'filesystems.disks.s3.secret', 'bucket' => 'filesystems.disks.s3.bucket', 'region' => 'filesystems.disks.s3.region', 'endpoint' => 'filesystems.disks.s3.endpoint', 'url' => 'filesystems.disks.s3.url'];
        $value = config($map[$field['key']] ?? '', '');

        return is_scalar($value) ? (string) $value : '';
    }

    /** @return array{level: string, note: string} */
    private function testAi(): array
    {
        $response = $this->gateway->request('ai', 'GET', '/v1/models', ['headers' => ['x-api-key' => $this->secrets->get('ai', 'api_key'), 'anthropic-version' => (string) $this->secrets->config('ai', 'version', '2023-06-01')]]);

        if ($response->status() === 401 || $response->status() === 403) {
            return ['level' => 'fail', 'note' => 'API anahtarı reddedildi (HTTP '.$response->status().'). Anahtarı yeniden girin.'];
        }

        if (! $response->successful()) {
            return ['level' => 'fail', 'note' => 'Sağlayıcı HTTP '.$response->status().' döndü.'];
        }

        $models = array_map(fn ($m) => (string) ($m['id'] ?? ''), (array) $response->json('data', []));
        $model = (string) $this->secrets->config('ai', 'model', 'claude-sonnet-5');

        return in_array($model, $models, true) || $models === []
            ? ['level' => 'ok', 'note' => 'Bağlantı başarılı — model '.$model.' kullanılabilir.']
            : ['level' => 'warn', 'note' => 'Bağlantı başarılı ama "'.$model.'" model listesinde yok; model adını kontrol edin.'];
    }

    /** @return array{level: string, note: string} */
    private function testGoogle(string $key): array
    {
        if ($key === 'search_console') {
            $sites = app(SearchConsoleClient::class)->sites();

            return ['level' => 'ok', 'note' => 'Bağlantı başarılı — servis hesabı '.count($sites).' mülke erişiyor'.($sites !== [] ? ': '.implode(', ', array_slice(array_column($sites, 'siteUrl'), 0, 3)) : ' (mülke servis hesabı e-postasını ekleyin)').'.'];
        }

        app(ServiceAccountAuth::class)->token($key);

        return ['level' => 'ok', 'note' => 'Bağlantı başarılı — servis hesabı belirteci alındı.'];
    }

    /** @return array{level: string, note: string} */
    private function testStorage(): array
    {
        $disk = Storage::disk('s3');
        $path = 'health/'.bin2hex(random_bytes(4)).'.txt';
        $ok = $disk->put($path, 'ok') && $disk->get($path) === 'ok';
        $disk->delete($path);

        return $ok ? ['level' => 'ok', 'note' => 'S3 bucket yazılıp okundu.'] : ['level' => 'fail', 'note' => 'S3 bucket yazılamadı.'];
    }

    private static function friendly(string $message): string
    {
        $message = mb_substr($message, 0, 220);

        return match (true) {
            str_contains($message, 'cURL error 6'), str_contains($message, 'Could not resolve') => 'Sunucu adı çözülemedi — Base URL/DNS kontrol edin.',
            str_contains($message, 'cURL error 7'), str_contains($message, 'Connection refused') => 'Bağlantı reddedildi — servis kapalı ya da port yanlış.',
            str_contains($message, 'cURL error 28'), str_contains($message, 'timed out') => 'Zaman aşımı — servis yanıt vermedi.',
            str_contains($message, 'certificate') => 'TLS sertifikası doğrulanamadı.',
            default => 'Bağlantı başarısız: '.$message,
        };
    }
}
