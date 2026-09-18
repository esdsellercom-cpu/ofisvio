<?php

namespace App\Integrations\Google;

use App\Integrations\Gateway;
use RuntimeException;

/**
 * GA4 Data API istemcisi (faz 60d) — yalnız Gateway (analytics sağlayıcısı, salt okunur). Mülk kimliği panel
 * ayarından. Yalnız organik trafik raporları çekilir (sessionDefaultChannelGroup = Organic Search).
 */
class AnalyticsClient
{
    public function __construct(private readonly Gateway $gateway, private readonly ServiceAccountAuth $auth) {}

    /**
     * runReport: boyut + metrik satırları.
     *
     * @param  list<string>  $dimensions
     * @param  list<string>  $metrics
     * @return list<array{dimensions: list<string>, metrics: list<float>}>
     */
    public function report(string $propertyId, string $startDate, string $endDate, array $dimensions, array $metrics, bool $organicOnly = true, int $limit = 250): array
    {
        $body = [
            'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
            'dimensions' => array_map(fn (string $d) => ['name' => $d], $dimensions),
            'metrics' => array_map(fn (string $m) => ['name' => $m], $metrics),
            'limit' => $limit,
        ];

        if ($organicOnly) {
            $body['dimensionFilter'] = ['filter' => ['fieldName' => 'sessionDefaultChannelGroup', 'stringFilter' => ['matchType' => 'EXACT', 'value' => 'Organic Search']]];
        }

        $response = $this->gateway->request('analytics', 'POST', '/v1beta/properties/'.rawurlencode($propertyId).':runReport', [
            'headers' => ['Authorization' => 'Bearer '.$this->auth->token('analytics')],
            'json' => $body,
            'timeout' => 30,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Analytics runReport HTTP '.$response->status().($response->status() === 403 ? ' — servis hesabı GA4 mülküne okuyucu olarak eklenmemiş olabilir.' : ''));
        }

        return array_values(array_map(fn (array $row) => [
            'dimensions' => array_values(array_map(fn ($v) => (string) ($v['value'] ?? ''), (array) ($row['dimensionValues'] ?? []))),
            'metrics' => array_values(array_map(fn ($v) => (float) ($v['value'] ?? 0), (array) ($row['metricValues'] ?? []))),
        ], (array) $response->json('rows', [])));
    }
}
