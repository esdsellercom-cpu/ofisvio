<?php

namespace App\Services;

use App\Integrations\Google\PageSpeedClient;
use App\Integrations\SecretStore;
use App\Models\IntegrationSyncState;
use App\Models\Website;
use App\Models\WebVitalsSample;
use App\Seo\SchemaInspector;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Core Web Vitals (faz 60d/60f): PageSpeed Insights ile temsilî sayfalar (ana sayfa + her türden ilk sayfa)
 * mobil/masaüstü ölçülür, örnek olarak saklanır. Eşikler Google'ın: LCP ≤ 2500 ms iyi / ≤ 4000 orta;
 * INP ≤ 200 / ≤ 500; CLS ≤ 0.1 / ≤ 0.25. Sağlayıcı kapalıysa ölçüm yok — panel "ölçülmedi" der.
 */
class WebVitalsService
{
    public const THRESHOLDS = ['lcp_ms' => [2500, 4000], 'inp_ms' => [200, 500], 'cls' => [0.1, 0.25], 'fcp_ms' => [1800, 3000], 'ttfb_ms' => [800, 1800]];

    public function __construct(
        private readonly PageSpeedClient $pagespeed,
        private readonly SecretStore $secrets,
        private readonly SchemaInspector $inspector,
    ) {}

    /**
     * Temsilî sayfalar: ana sayfa + her türden ilk sayfa (hizmet, lokasyon, yazı, sayfa, hizmet × şehir).
     *
     * @return list<string>
     */
    public function representativePaths(Website $website): array
    {
        $paths = ['/'];
        $seen = [];

        foreach ($this->inspector->pages($website) as $page) {
            if (in_array($page['kind'], ['service', 'location', 'post', 'page', 'landing'], true) && ! isset($seen[$page['kind']])) {
                $seen[$page['kind']] = true;
                $paths[] = $page['path'];
            }
        }

        return $paths;
    }

    /**
     * Ölçüm: verilen yollar × stratejiler; hata sayfayı atlar, senkron durumuna yazılır.
     *
     * @param  list<string>|null  $paths
     * @return array{measured: int, errors: list<string>}
     */
    public function measure(Website $website, ?array $paths = null, array $strategies = ['mobile', 'desktop']): array
    {
        $state = IntegrationSyncState::query()->firstOrNew(['website_id' => $website->id, 'provider' => 'pagespeed']);
        $state->last_attempt_at = now();

        if (! $this->secrets->enabled('pagespeed')) {
            $state->last_error = 'PAGESPEED_ENABLED kapalı.';
            $state->save();

            return ['measured' => 0, 'errors' => ['PageSpeed sağlayıcısı kapalı (env).']];
        }

        $base = $website->baseUrl();
        $measured = 0;
        $errors = [];

        foreach ($paths ?? $this->representativePaths($website) as $path) {
            foreach ($strategies as $strategy) {
                try {
                    $result = $this->pagespeed->measure($base.$path, $strategy);
                    WebVitalsSample::query()->create(['website_id' => $website->id, 'path' => $path, 'strategy' => $strategy, 'score' => $result['score'], 'lab' => $result['lab'], 'field' => $result['field'], 'measured_at' => now()]);
                    $measured++;
                } catch (Throwable $e) {
                    $errors[] = $path.' ('.$strategy.'): '.mb_substr($e->getMessage(), 0, 160);
                }
            }
        }

        $state->last_success_at = $measured > 0 ? now() : $state->last_success_at;
        $state->last_error = $errors === [] ? null : mb_substr(implode(' · ', $errors), 0, 500);
        $state->save();

        return ['measured' => $measured, 'errors' => $errors];
    }

    /**
     * Son ölçümler: yol × strateji için en yeni örnek + derece.
     *
     * @return Collection<int, array{sample: WebVitalsSample, grades: array<string, string>}>
     */
    public function latest(Website $website): Collection
    {
        $measurements = WebVitalsSample::query()->where('website_id', $website->id)->orderByDesc('measured_at')->limit(400)->get();
        $latest = [];

        foreach ($measurements as $sample) {
            $key = $sample->path.'|'.$sample->strategy;

            if (! isset($latest[$key])) {
                $latest[$key] = ['sample' => $sample, 'grades' => $this->grades($sample)];
            }
        }

        return collect(array_values($latest));
    }

    public function state(Website $website): ?IntegrationSyncState
    {
        return IntegrationSyncState::query()->where('website_id', $website->id)->where('provider', 'pagespeed')->first();
    }

    /**
     * Metrik derecesi: good | needs | poor | none (alan verisi varsa o, yoksa lab).
     *
     * @return array<string, string>
     */
    public function grades(WebVitalsSample $sample): array
    {
        $out = [];

        foreach (self::THRESHOLDS as $metric => [$good, $needs]) {
            $value = $sample->field[$metric] ?? $sample->lab[$metric] ?? null;
            $out[$metric] = $value === null ? 'none' : ($value <= $good ? 'good' : ($value <= $needs ? 'needs' : 'poor'));
        }

        return $out;
    }
}
