<?php

namespace App\Jobs;

use App\Integrations\Gateway;
use App\Models\Website;
use App\Services\SeoSettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * IndexNow bildirimi (faz 44): api.indexnow.org'a adres listesi. Dış istek yalnız Integration Gateway
 * üzerinden (sağlayıcı 'indexnow' env ile açılır; SSRF denetimi, log). Sağlayıcı kapalıysa sessizce geçer.
 */
class NotifyIndexNow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @param  array<int, string>  $urls */
    public function __construct(public readonly int $websiteId, public readonly array $urls) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 600, 3600];
    }

    public function handle(Gateway $gateway, SeoSettingsService $settings): void
    {
        $website = Website::query()->find($this->websiteId);

        if ($website === null || ! config('integrations.providers.indexnow.enabled')) {
            return;
        }

        $key = $settings->string($website, 'indexing.indexnow_key');

        if ($key === '' || $this->urls === []) {
            return;
        }

        $host = (string) parse_url($website->baseUrl(), PHP_URL_HOST);

        $gateway->request('indexnow', 'POST', '/indexnow', ['json' => [
            'host' => $host,
            'key' => $key,
            'keyLocation' => $website->baseUrl().'/'.$key.'.txt',
            'urlList' => array_values(array_unique($this->urls)),
        ]]);
    }
}
