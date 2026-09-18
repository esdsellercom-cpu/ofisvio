<?php

namespace App\Integrations\Google;

use App\Integrations\Gateway;
use App\Integrations\SecretStore;
use RuntimeException;

/**
 * PageSpeed Insights (faz 60d) — Core Web Vitals lab (Lighthouse) + alan (CrUX) verisi; yalnız Gateway.
 * JS'ten ölçüm gönderilmez (istemci HTTP çağrısı yasağı); ölçüm sunucudan komutla çekilir.
 */
class PageSpeedClient
{
    public function __construct(private readonly Gateway $gateway, private readonly SecretStore $secrets) {}

    /**
     * @return array{score: int|null, lab: array<string, float|null>, field: array<string, float|null>, fetched_at: string}
     */
    public function measure(string $url, string $strategy = 'mobile'): array
    {
        $query = ['url' => $url, 'strategy' => $strategy, 'category' => 'performance'];
        $key = (string) $this->secrets->config('pagespeed', 'api_key', '');

        if ($key !== '') {
            $query['key'] = $key;
        }

        $response = $this->gateway->request('pagespeed', 'GET', '/pagespeedonline/v5/runPagespeed', ['query' => $query, 'timeout' => 60]);

        if (! $response->successful()) {
            throw new RuntimeException('PageSpeed HTTP '.$response->status());
        }

        $audits = (array) $response->json('lighthouseResult.audits', []);
        $metric = fn (string $id) => isset($audits[$id]['numericValue']) ? (float) $audits[$id]['numericValue'] : null;
        $field = (array) $response->json('loadingExperience.metrics', []);
        $p75 = fn (string $id) => isset($field[$id]['percentile']) ? (float) $field[$id]['percentile'] : null;
        $score = $response->json('lighthouseResult.categories.performance.score');

        return [
            'score' => is_numeric($score) ? (int) round((float) $score * 100) : null,
            'lab' => [
                'lcp_ms' => $metric('largest-contentful-paint'),
                'cls' => $metric('cumulative-layout-shift'),
                'inp_ms' => $metric('interaction-to-next-paint') ?? $metric('total-blocking-time'),
                'fcp_ms' => $metric('first-contentful-paint'),
                'ttfb_ms' => $metric('server-response-time'),
                'speed_index_ms' => $metric('speed-index'),
            ],
            'field' => [
                'lcp_ms' => $p75('LARGEST_CONTENTFUL_PAINT_MS'),
                'cls' => $p75('CUMULATIVE_LAYOUT_SHIFT_SCORE') !== null ? $p75('CUMULATIVE_LAYOUT_SHIFT_SCORE') / 100 : null,
                'inp_ms' => $p75('INTERACTION_TO_NEXT_PAINT'),
                'fcp_ms' => $p75('FIRST_CONTENTFUL_PAINT_MS'),
                'ttfb_ms' => $p75('EXPERIMENTAL_TIME_TO_FIRST_BYTE'),
            ],
            'fetched_at' => now()->toIso8601String(),
        ];
    }
}
