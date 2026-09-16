<?php

namespace App\Integrations;

use RuntimeException;

/**
 * SSRF koruması (faz 5). Bir dış adres yalnız şu şartlarla çağrılır:
 *   - şema https, kullanıcı bilgisi (user:pass@) yok, port 443 (ya da belirtilmemiş)
 *   - ana bilgisayar sağlayıcının config'teki base_url ana bilgisayarıyla aynı
 *   - ana bilgisayar IP literali değil; DNS çözümü özel/loopback/link-local/
 *     metadata aralığına düşmüyor (DNS rebinding'e karşı çözümlenen IP denetlenir)
 * Yönlendirmeler geçitte kapalıdır (allow_redirects=false); bu sınıf hedefi
 * yalnız bir kez doğrular.
 */
final class UrlGuard
{
    /** @param  callable(string): (array<int, string>|false)  $resolver  test için DNS enjeksiyonu */
    public function __construct(private $resolver = null) {}

    /** Doğrulanmış mutlak URL döner; aksi RuntimeException (mesaj log'a uygun, secret içermez). */
    public function assertAllowed(string $baseUrl, string $path): string
    {
        $base = parse_url($baseUrl);

        if (! is_array($base) || ($base['scheme'] ?? '') !== 'https' || empty($base['host'])) {
            throw new RuntimeException('Sağlayıcı base_url https:// ile başlayan geçerli bir adres olmalı.');
        }

        if (isset($base['user']) || isset($base['pass'])) {
            throw new RuntimeException('base_url kullanıcı bilgisi taşıyamaz.');
        }

        if (isset($base['port']) && (int) $base['port'] !== 443) {
            throw new RuntimeException('Yalnız 443 portu.');
        }

        // Yol mutlak URL olamaz (başka ana bilgisayara kaçış yok).
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1 || str_starts_with($path, '//')) {
            throw new RuntimeException('İstek yolu mutlak adres olamaz; sağlayıcı base_url dışına çıkılmaz.');
        }

        $host = strtolower($base['host']);
        $this->assertPublicHost($host);

        return rtrim($baseUrl, '/').'/'.ltrim($path, '/');
    }

    public function assertPublicHost(string $host): void
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new RuntimeException('IP literali hedef olamaz; alan adı gerekir.');
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new RuntimeException('Yerel ana bilgisayar adları hedef olamaz.');
        }

        $ips = $this->resolve($host);

        if ($ips === [] || $ips === false) {
            throw new RuntimeException('Ana bilgisayar çözümlenemedi: '.$host);
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                throw new RuntimeException("Hedef özel/yerel ağa çözümleniyor ({$host}); SSRF koruması reddetti.");
            }
        }
    }

    /** @return array<int, string>|false */
    private function resolve(string $host): array|false
    {
        if ($this->resolver !== null) {
            return ($this->resolver)($host);
        }

        $ipv4 = gethostbynamel($host);
        $ips = $ipv4 === false ? [] : $ipv4;

        foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = (string) $record['ipv6'];
            }
        }

        return $ips === [] ? false : $ips;
    }

    public static function isPublicIp(string $ip): bool
    {
        // FILTER_FLAG_NO_PRIV_RANGE + NO_RES_RANGE: 10/8, 172.16/12, 192.168/16, 127/8, 169.254/16, 0/8, ::1, fc00::/7, fe80::/10 …
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // Bulut metadata (169.254.169.254 üstteki bayrakla yakalanır) + IPv4-mapped IPv6 (::ffff:10.0.0.1).
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            return self::isPublicIp(substr($ip, 7));
        }

        return true;
    }
}
