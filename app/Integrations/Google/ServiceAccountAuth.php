<?php

namespace App\Integrations\Google;

use App\Integrations\Gateway;
use App\Integrations\SecretStore;
use Illuminate\Contracts\Cache\Repository;
use RuntimeException;

/**
 * Google servis hesabı kimlik doğrulaması (faz 60d): env'deki servis hesabı JSON'u (Search Console / Analytics
 * sağlayıcısının `service_account_json` secret'ı — JSON metni ya da dosya yolu) ile RS256 imzalı JWT üretilir,
 * Gateway üzerinden (google_oauth sağlayıcısı) erişim belirtecine çevrilir; belirteç 50 dk önbelleklenir.
 * Özel anahtar hiçbir yerde loglanmaz; JSON yalnız burada okunur.
 */
class ServiceAccountAuth
{
    private const SCOPES = [
        'search_console' => 'https://www.googleapis.com/auth/webmasters.readonly',
        'analytics' => 'https://www.googleapis.com/auth/analytics.readonly',
    ];

    public function __construct(private readonly Gateway $gateway, private readonly SecretStore $secrets, private readonly Repository $cache) {}

    /** Sağlayıcı (search_console | analytics) için erişim belirteci. */
    public function token(string $provider): string
    {
        $scope = self::SCOPES[$provider] ?? throw new RuntimeException('Bilinmeyen Google sağlayıcısı: '.$provider);
        $account = $this->account($provider);
        $cacheKey = 'google:token:'.$provider.':'.sha1((string) $account['client_email']);
        $cached = $this->cache->get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $assertion = $this->assertion($account, $scope);
        $response = $this->gateway->request('google_oauth', 'POST', '/token', ['form' => ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion]]);

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new RuntimeException('Google belirteci alınamadı (HTTP '.$response->status().').');
        }

        $token = (string) $response->json('access_token');
        $this->cache->put($cacheKey, $token, now()->addMinutes(50));

        return $token;
    }

    /**
     * Servis hesabı alanları (client_email, private_key, token_uri).
     *
     * @return array{client_email: string, private_key: string}
     */
    public function account(string $provider): array
    {
        $raw = $this->secrets->get($provider, 'service_account_json');

        // Dosya yolu verilmişse (uzun JSON env'e sığmaz) dosyadan oku; yalnız yerel dosya sistemi.
        if (! str_starts_with(trim($raw), '{') && is_file($raw)) {
            $raw = (string) file_get_contents($raw);
        }

        $json = json_decode($raw, true);

        if (! is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
            throw new RuntimeException('Servis hesabı JSON\'u geçersiz: client_email / private_key eksik.');
        }

        return ['client_email' => (string) $json['client_email'], 'private_key' => (string) $json['private_key']];
    }

    /**
     * RS256 JWT (iss = servis hesabı, aud = token ucu, 1 saat).
     *
     * @param  array{client_email: string, private_key: string}  $account
     */
    public function assertion(array $account, string $scope, ?int $now = null): string
    {
        $now ??= time();
        $header = self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES) ?: '');
        $claims = self::b64(json_encode([
            'iss' => $account['client_email'],
            'scope' => $scope,
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_UNESCAPED_SLASHES) ?: '');
        $key = openssl_pkey_get_private($account['private_key']);

        if ($key === false) {
            throw new RuntimeException('Servis hesabı özel anahtarı okunamadı.');
        }

        $signature = '';

        if (! openssl_sign($header.'.'.$claims, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('JWT imzalanamadı.');
        }

        return $header.'.'.$claims.'.'.self::b64($signature);
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
