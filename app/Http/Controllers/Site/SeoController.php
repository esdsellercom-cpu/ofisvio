<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\CurrentWebsite;
use App\Services\SeoService;
use App\Services\SeoSettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

/**
 * robots.txt, sitemap.xml, llms.txt, HTML site haritası ve IndexNow anahtar dosyası — geçerli
 * website'e göre (Host çözümlemesi). Website yoksa (seed edilmemiş) "hiçbir şeyi indeksleme".
 * Gelişmiş ayarlar (faz 44): sitemap kapalı → 404, özel sitemap/robots olduğu gibi.
 */
class SeoController extends Controller
{
    public function __construct(
        private readonly SeoService $seo,
        private readonly SeoSettingsService $settings,
        private readonly CurrentWebsite $website,
    ) {}

    public function robots(): Response
    {
        $site = $this->website->get();
        $body = $site === null ? "User-agent: *\nDisallow: /\n" : $this->seo->robotsTxt($site);

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(): Response
    {
        $site = $this->website->get();

        if ($site !== null && ! $this->settings->bool($site, 'crawl.sitemap_enabled')) {
            abort(404);
        }

        $custom = $site === null ? '' : $this->settings->string($site, 'crawl.sitemap_custom');

        if ($custom !== '') {
            return response($custom."\n", 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
        }

        $entries = $site === null ? [] : $this->seo->sitemapEntries($site);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($entries as $entry) {
            $xml .= '  <url><loc>'.e($entry['loc']).'</loc>';
            if ($entry['lastmod'] !== null) {
                $xml .= '<lastmod>'.e($entry['lastmod']).'</lastmod>';
            }
            $xml .= '<changefreq>'.$entry['changefreq'].'</changefreq><priority>'.$entry['priority'].'</priority></url>'."\n";
        }

        $xml .= '</urlset>'."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /** llms.txt (GEO): kapalıysa 404. */
    public function llms(): Response
    {
        $site = $this->website->get();
        $body = $site === null ? null : $this->seo->llmsTxt($site);

        if ($body === null) {
            abort(404);
        }

        return response($body, 200, ['Content-Type' => 'text/markdown; charset=UTF-8']);
    }

    /** HTML site haritası (/site-haritasi): ayar kapalıysa 404. */
    public function htmlSitemap(): View
    {
        $site = $this->website->get();

        if ($site === null || ! $this->settings->bool($site, 'technical.html_sitemap')) {
            abort(404);
        }

        return view('site.sitemap', ['sections' => $this->seo->htmlSitemap($site)]);
    }

    /** IndexNow anahtar dosyası (/{anahtar}.txt): yalnız kayıtlı anahtar. */
    public function indexNowKey(string $key): Response
    {
        $site = $this->website->get();

        if ($site === null || $key === '' || $this->settings->string($site, 'indexing.indexnow_key') !== $key) {
            abort(404);
        }

        return response($key, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
