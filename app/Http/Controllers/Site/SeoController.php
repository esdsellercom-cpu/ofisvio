<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\CurrentWebsite;
use App\Services\SeoService;
use Illuminate\Http\Response;

/**
 * robots.txt ve sitemap.xml — geçerli website'e göre (Host çözümlemesi).
 * Website yoksa (seed edilmemiş) her ikisi de "hiçbir şeyi indeksleme" der.
 */
class SeoController extends Controller
{
    public function __construct(
        private readonly SeoService $seo,
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
}
