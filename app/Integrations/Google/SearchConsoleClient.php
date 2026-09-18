<?php

namespace App\Integrations\Google;

use App\Integrations\Gateway;
use RuntimeException;

/**
 * Search Console API istemcisi (faz 60d) — yalnız Gateway üzerinden (search_console sağlayıcısı, salt okunur).
 * Mülk adresi (sc-domain:alan.com ya da https://alan.com/) panel ayarından gelir; veri ham satır olarak döner,
 * saklama/özetleme SearchPerformanceService'te.
 */
class SearchConsoleClient
{
    public function __construct(private readonly Gateway $gateway, private readonly ServiceAccountAuth $auth) {}

    /**
     * Servis hesabının erişebildiği mülkler (doğrulama durumu: permissionLevel).
     *
     * @return list<array{siteUrl: string, permissionLevel: string}>
     */
    public function sites(): array
    {
        $response = $this->gateway->request('search_console', 'GET', '/webmasters/v3/sites', ['headers' => $this->headers()]);
        $this->assertOk($response->status(), 'sites');

        return array_values(array_map(fn (array $row) => ['siteUrl' => (string) ($row['siteUrl'] ?? ''), 'permissionLevel' => (string) ($row['permissionLevel'] ?? '')], (array) $response->json('siteEntry', [])));
    }

    /**
     * Arama analitiği sorgusu.
     *
     * @param  list<string>  $dimensions  query | page | country | device | date
     * @return list<array{keys: list<string>, clicks: int, impressions: int, ctr: float, position: float}>
     */
    public function query(string $siteUrl, string $startDate, string $endDate, array $dimensions, int $rowLimit = 250): array
    {
        $response = $this->gateway->request('search_console', 'POST', '/webmasters/v3/sites/'.rawurlencode($siteUrl).'/searchAnalytics/query', [
            'headers' => $this->headers(),
            'json' => ['startDate' => $startDate, 'endDate' => $endDate, 'dimensions' => $dimensions, 'rowLimit' => $rowLimit, 'type' => 'web'],
            'timeout' => 30,
        ]);
        $this->assertOk($response->status(), 'searchAnalytics/query');

        return array_values(array_map(fn (array $row) => [
            'keys' => array_values(array_map('strval', (array) ($row['keys'] ?? []))),
            'clicks' => (int) ($row['clicks'] ?? 0),
            'impressions' => (int) ($row['impressions'] ?? 0),
            'ctr' => (float) ($row['ctr'] ?? 0),
            'position' => (float) ($row['position'] ?? 0),
        ], (array) $response->json('rows', [])));
    }

    /**
     * Gönderilmiş sitemap'ler ve durumları (indeksleme sorunları için).
     *
     * @return list<array{path: string, lastSubmitted: string|null, lastDownloaded: string|null, isPending: bool, errors: int, warnings: int, submitted: int, indexed: int}>
     */
    public function sitemaps(string $siteUrl): array
    {
        $response = $this->gateway->request('search_console', 'GET', '/webmasters/v3/sites/'.rawurlencode($siteUrl).'/sitemaps', ['headers' => $this->headers()]);
        $this->assertOk($response->status(), 'sitemaps');
        $out = [];

        foreach ((array) $response->json('sitemap', []) as $row) {
            $submitted = 0;
            $indexed = 0;

            foreach ((array) ($row['contents'] ?? []) as $content) {
                $submitted += (int) ($content['submitted'] ?? 0);
                $indexed += (int) ($content['indexed'] ?? 0);
            }

            $out[] = [
                'path' => (string) ($row['path'] ?? ''),
                'lastSubmitted' => isset($row['lastSubmitted']) ? (string) $row['lastSubmitted'] : null,
                'lastDownloaded' => isset($row['lastDownloaded']) ? (string) $row['lastDownloaded'] : null,
                'isPending' => (bool) ($row['isPending'] ?? false),
                'errors' => (int) ($row['errors'] ?? 0),
                'warnings' => (int) ($row['warnings'] ?? 0),
                'submitted' => $submitted,
                'indexed' => $indexed,
            ];
        }

        return $out;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->auth->token('search_console')];
    }

    private function assertOk(int $status, string $what): void
    {
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Search Console '.$what.' HTTP '.$status.($status === 403 ? ' — servis hesabı mülke eklenmemiş olabilir.' : ''));
        }
    }
}
