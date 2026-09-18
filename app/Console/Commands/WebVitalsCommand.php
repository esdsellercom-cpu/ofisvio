<?php

namespace App\Console\Commands;

use App\Services\ContentService;
use App\Services\WebVitalsService;
use Illuminate\Console\Command;

/**
 * Core Web Vitals ölçümü (faz 60d): PageSpeed Insights ile temsilî sayfalar (ya da --path) mobil/masaüstü.
 * JS'ten ölçüm gönderilmez; ölçüm sunucudan, Gateway üzerinden.
 */
class WebVitalsCommand extends Command
{
    protected $signature = 'ofisvio:web-vitals {--path=* : Ölçülecek yol(lar); boş = temsilî sayfalar} {--strategy=* : mobile / desktop}';

    protected $description = 'Core Web Vitals ölçer (PageSpeed Insights, yalnız sağlayıcı açıkken).';

    public function handle(WebVitalsService $vitals, ContentService $contents): int
    {
        $paths = array_values(array_filter(array_map('strval', (array) $this->option('path'))));
        $strategies = array_values(array_intersect(['mobile', 'desktop'], array_map('strval', (array) $this->option('strategy')))) ?: ['mobile', 'desktop'];
        $result = $vitals->measure($contents->defaultWebsite(), $paths === [] ? null : $paths, $strategies);
        $this->info($result['measured'].' ölçüm kaydedildi.');

        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return $result['measured'] === 0 && $result['errors'] !== [] ? self::FAILURE : self::SUCCESS;
    }
}
