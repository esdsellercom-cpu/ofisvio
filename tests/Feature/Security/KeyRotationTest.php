<?php

namespace Tests\Feature\Security;

use App\Integrations\IntegrationConfigRepository;
use App\Integrations\SecretStore;
use App\Models\AuditLog;
use App\Models\WebhookEndpoint;
use App\Services\KeyRotationService;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Audit F-14: APP_KEY rotasyonu gerçek akışla — eski anahtarla şifreli kayıtlar previous_keys ile okunmaya devam eder,
 * ofisvio:reencrypt yeni anahtarla yazar, eski anahtar kaldırılınca hâlâ okunur; doctor bekleyen kaydı raporlar.
 */
class KeyRotationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function eski_anahtarla_sifreli_kayitlar_yeniden_sifrelenir_ve_eski_anahtar_kaldirilabilir(): void
    {
        $oldKey = Encrypter::generateKey('AES-256-CBC');
        $newKey = Encrypter::generateKey('AES-256-CBC');
        $old = new Encrypter($oldKey, 'AES-256-CBC');

        // 1) Eski anahtarla şifrelenmiş kayıtlar (panel secret'ı, webhook secret'ı, TC kimlik).
        DB::table('integration_secrets')->insert(['provider' => 'ai', 'field' => 'api_key', 'value' => $old->encryptString('sk-old-1234567890'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('webhook_endpoints')->insert(['name' => 'CRM', 'url' => 'https://crm.example.com/in', 'secret' => $old->encryptString('whsec_eski'), 'events' => '["lead.created"]', 'created_at' => now(), 'updated_at' => now()]);

        // 2) Rotasyon: APP_KEY=yeni, APP_PREVIOUS_KEYS=eski → uygulama eski kayıtları çözebilir.
        $this->rebindEncrypter($newKey, [$oldKey]);
        app(IntegrationConfigRepository::class)->forget();
        $this->assertSame('sk-old-1234567890', app(SecretStore::class)->get('ai', 'api_key'));
        $this->assertSame('whsec_eski', WebhookEndpoint::query()->firstOrFail()->secret);

        $pending = app(KeyRotationService::class)->pending();
        $this->assertSame(1, $pending['integration_secrets.value']);
        $this->assertSame(1, $pending['webhook_endpoints.secret']);
        $this->artisan('ofisvio:doctor')->expectsOutputToContain('2 kayıt eski anahtarla şifreli');

        // 3) Yeniden şifrele: dry-run değiştirmez; gerçek koşu yeni anahtarla yazar, audit düşer (değer yazmaz).
        $this->artisan('ofisvio:reencrypt', ['--dry-run' => true])->expectsOutputToContain('Eski anahtarla şifreli: 2 kayıt')->assertSuccessful();
        $this->assertSame(1, app(KeyRotationService::class)->pending()['webhook_endpoints.secret']);
        $this->artisan('ofisvio:reencrypt')->expectsOutputToContain('Yeniden şifrelendi: 2 kayıt')->assertSuccessful();
        $this->assertSame(0, array_sum(app(KeyRotationService::class)->pending()));
        $this->assertTrue(AuditLog::query()->where('action', 'security.key_rotated')->exists());
        $this->assertStringNotContainsString('sk-old', json_encode(AuditLog::query()->get()->toArray()));

        // 4) Eski anahtar kaldırılır: kayıtlar hâlâ okunur; doctor ok.
        $this->rebindEncrypter($newKey, []);
        app(IntegrationConfigRepository::class)->forget();
        $this->assertSame('sk-old-1234567890', app(SecretStore::class)->get('ai', 'api_key'));
        $this->assertSame('whsec_eski', WebhookEndpoint::query()->firstOrFail()->secret);
        $this->assertSame('whsec_eski', Crypt::decryptString((string) DB::table('webhook_endpoints')->value('secret')));
        $this->artisan('ofisvio:doctor')->expectsOutputToContain('tüm şifreli kayıtlar mevcut anahtarla');
    }

    /** @param  array<int, string>  $previous */
    private function rebindEncrypter(string $key, array $previous): void
    {
        config(['app.key' => 'base64:'.base64_encode($key), 'app.previous_keys' => array_map(fn (string $k) => 'base64:'.base64_encode($k), $previous)]);
        $encrypter = new Encrypter($key, 'AES-256-CBC');
        $encrypter->previousKeys($previous);
        $this->app->instance('encrypter', $encrypter);
        $this->app->instance(\Illuminate\Contracts\Encryption\Encrypter::class, $encrypter);
        $this->app->instance(StringEncrypter::class, $encrypter);
        Crypt::clearResolvedInstances();
    }
}
