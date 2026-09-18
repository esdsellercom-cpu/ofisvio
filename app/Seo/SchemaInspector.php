<?php

namespace App\Seo;

use App\Enums\ContentKind;
use App\Models\Website;
use App\Services\ContentService;
use App\Services\EventService;
use App\Services\GeoService;
use App\Services\SeoService;
use App\Services\ServiceService;
use Illuminate\Support\Str;

/**
 * Sayfa → head/JSON-LD çözümleyici (faz 60, Schema Manager + Command Center öz denetimi). Vitrindeki composer
 * hangi sayfaya hangi head'i basıyorsa burada aynı üretici çağrılır; böylece panel "bu sayfada şu şema basılıyor"
 * derken gerçek çıktıyı gösterir (ayrı bir kopya değil). Yalnız var olan sayfalar: yayındaki içerik/hizmet/lokasyon/
 * etkinlik ve sabit sayfalar.
 */
class SchemaInspector
{
    public function __construct(
        private readonly SeoService $seo,
        private readonly ContentService $contents,
        private readonly GeoService $geo,
        private readonly ServiceService $services,
        private readonly EventService $events,
        private readonly SchemaValidator $validator,
    ) {}

    /**
     * Sitenin bilinen sayfaları (yol, etiket, tür).
     *
     * @return list<array{path: string, label: string, kind: string}>
     */
    public function pages(Website $website): array
    {
        $pages = [['path' => '/', 'label' => 'Ana sayfa', 'kind' => 'home']];

        if ($website->is_default) {
            $pages[] = ['path' => '/cozumler', 'label' => 'Çözümler', 'kind' => 'static'];

            foreach ($this->services->active($website) as $service) {
                $pages[] = ['path' => $service->path(), 'label' => $service->name, 'kind' => 'service'];
            }

            $pages[] = ['path' => '/lokasyonlar', 'label' => 'Lokasyonlar', 'kind' => 'static'];

            foreach ($this->geo->publishedLocations() as $location) {
                $pages[] = ['path' => $location->path(), 'label' => $location->name, 'kind' => 'location'];
            }

            $pages[] = ['path' => '/etkinlikler', 'label' => 'Etkinlikler', 'kind' => 'static'];

            foreach ($this->events->upcoming($website) as $event) {
                $pages[] = ['path' => $event->path(), 'label' => $event->title, 'kind' => 'event'];
            }

            $pages[] = ['path' => '/franchise', 'label' => 'Franchise', 'kind' => 'static'];
        }

        foreach ($this->contents->livePages($website) as $page) {
            $pages[] = ['path' => $page->path(), 'label' => $page->title, 'kind' => 'page'];
        }

        $pages[] = ['path' => '/blog', 'label' => 'Yazılar', 'kind' => 'static'];

        foreach ($this->contents->livePosts($website, 1000) as $post) {
            $pages[] = ['path' => $post->path(), 'label' => $post->title, 'kind' => 'post'];
        }

        foreach ($this->contents->categories($website) as $slug => $category) {
            $pages[] = ['path' => '/blog/kategori/'.$slug, 'label' => 'Kategori: '.$category['name'], 'kind' => 'listing'];
        }

        foreach ($this->contents->tags($website) as $slug => $tag) {
            $pages[] = ['path' => '/blog/etiket/'.$slug, 'label' => 'Etiket: '.$tag['name'], 'kind' => 'listing'];
        }

        return $pages;
    }

    /**
     * Yolun head verisi (title, canonical, robots, json_ld…); sayfa yoksa null.
     *
     * @return array<string, mixed>|null
     */
    public function headFor(Website $website, string $path): ?array
    {
        $path = '/'.trim($path, '/');

        if ($path === '/') {
            return $this->seo->head($website, null, '/', $website->name);
        }

        if ($website->is_default) {
            if ($path === '/cozumler') {
                return $this->seo->head($website, null, '/cozumler', 'Çözümler');
            }
            if ($path === '/lokasyonlar') {
                return $this->seo->head($website, null, '/lokasyonlar', 'Lokasyonlar');
            }
            if ($path === '/etkinlikler') {
                return $this->seo->head($website, null, '/etkinlikler', 'Etkinlikler');
            }
            if ($path === '/franchise') {
                return $this->seo->head($website, null, '/franchise', 'Franchise');
            }
            if (str_starts_with($path, '/cozum/')) {
                $service = $this->services->findActive(Str::after($path, '/cozum/'));

                return $service === null ? null : $this->seo->serviceHead($website, $service);
            }
            if (str_starts_with($path, '/lokasyon/')) {
                $location = $this->geo->findPublished(Str::after($path, '/lokasyon/'));

                return $location === null ? null : $this->seo->locationHead($website, $location);
            }
            if (str_starts_with($path, '/etkinlik/')) {
                $event = $this->events->findPublished(Str::after($path, '/etkinlik/'));

                return $event === null ? null : $this->seo->eventHead($website, $event);
            }
        }

        if ($path === '/blog') {
            return $this->seo->head($website, null, '/blog', 'Yazılar');
        }

        if (str_starts_with($path, '/blog/kategori/')) {
            $slug = Str::after($path, '/blog/kategori/');

            return isset($this->contents->categories($website)[$slug]) ? $this->seo->head($website, null, $path, 'Kategori', null, 'listing') : null;
        }

        if (str_starts_with($path, '/blog/etiket/')) {
            $slug = Str::after($path, '/blog/etiket/');

            return isset($this->contents->tags($website)[$slug]) ? $this->seo->head($website, null, $path, 'Etiket', null, 'listing') : null;
        }

        if (str_starts_with($path, '/blog/')) {
            $post = $this->contents->findLive($website, ContentKind::POST, Str::after($path, '/blog/'));

            return $post === null ? null : $this->seo->head($website, $post);
        }

        $segments = explode('/', trim($path, '/'));

        if (count($segments) === 1) {
            $page = $this->contents->findLivePage($website, $segments[0]);

            return $page === null ? null : $this->seo->head($website, $page);
        }

        if (count($segments) === 2) {
            $page = $this->contents->findLivePage($website, $segments[1], $segments[0]);

            return $page === null ? null : $this->seo->head($website, $page);
        }

        return null;
    }

    /**
     * Tek sayfa incelemesi: head + doğrulama sonucu.
     *
     * @return array{path: string, head: array<string, mixed>, json: string, validation: array{errors: list<string>, warnings: list<string>, types: list<string>}}|null
     */
    public function inspect(Website $website, string $path): ?array
    {
        $head = $this->headFor($website, $path);

        if ($head === null) {
            return null;
        }

        $jsonLd = is_array($head['json_ld'] ?? null) ? $head['json_ld'] : [];

        return [
            'path' => '/'.trim($path, '/'),
            'head' => $head,
            'json' => $jsonLd === [] ? '' : (string) json_encode($jsonLd, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'validation' => $this->validator->validate($jsonLd),
        ];
    }
}
