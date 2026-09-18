<?php

namespace App\Services;

use App\Integrations\Gateway;
use App\Models\Content;
use App\Models\ContentUrlHistory;
use App\Models\Location;
use App\Models\NotFoundLog;
use App\Models\Service;
use App\Models\UrlRedirect;
use App\Models\User;
use App\Models\Website;
use App\Seo\RedirectMatcher;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Akıllı URL / yönlendirme merkezi (faz 54): benzer içerik önerisi, 404 karar zinciri, öneri onayı, 404 günlüğü,
 * kırık URL / yönlendirme botu ve panel istatistikleri. Yazma UrlHistoryService üzerinden.
 *
 * 404 karar zinciri (yalnız vitrin GET):
 *   URL geçmişi/etkin yönlendirme (middleware'de çözülür) → benzerlik ≥ otomatik eşik: 301 kur ve yönlendir
 *   → onay eşiği ile arası: öneri kaydı (pending) + 404 sayfasında "belki aradığınız" → eşik altı ve adres geçmişte
 *   GERÇEKTEN var olmuşsa: ayara göre üst kategori/hizmetler/lokasyonlar listesi ya da ana sayfa → aksi halde 404
 *   (rastgele adres hiçbir zaman ana sayfaya yönlendirilmez — soft 404 üretmemek için).
 */
class RedirectService
{
    public const STATIC_PATHS = ['/', '/blog', '/cozumler', '/lokasyonlar', '/etkinlikler', '/franchise', '/rezervasyon', '/site-haritasi', '/robots.txt', '/sitemap.xml', '/llms.txt', '/login'];

    public const MAX_EXTERNAL_PROBES = 25;

    public function __construct(
        private readonly ContentService $contents,
        private readonly ServiceService $services,
        private readonly GeoService $geo,
        private readonly EventService $events,
        private readonly UrlHistoryService $history,
        private readonly SeoSettingsService $settings,
        private readonly SeoService $seo,
        private readonly ContentCache $cache,
        private readonly Gateway $gateway,
    ) {}

    // ---- Adaylar ve benzerlik -------------------------------------------------------

    /**
     * Mevcut (canlı) içerikler: yazı, sayfa, hizmet, lokasyon, kategori sayfası. İstek içi memo.
     *
     * @return list<array{path: string, title: string, kind: string, category?: string|null, tags?: array<int, string>|null, text?: string|null}>
     */
    public function candidates(Website $website): array
    {
        return $this->cache->memo('redirect.candidates.'.$website->id, function () use ($website): array {
            $out = [];

            foreach ($this->contents->livePosts($website, 1000) as $post) {
                $out[] = ['path' => $post->path(), 'title' => $post->title, 'kind' => 'post', 'category' => $post->category, 'tags' => (array) ($post->tags ?? []), 'text' => trim((string) $post->excerpt.' '.$post->meta_description.' '.$post->body)];
            }

            foreach ($this->contents->livePages($website) as $page) {
                $out[] = ['path' => $page->path(), 'title' => $page->title, 'kind' => 'page', 'category' => $page->category, 'tags' => (array) ($page->tags ?? []), 'text' => trim((string) $page->excerpt.' '.$page->meta_description.' '.$page->body)];
            }

            if ($website->is_default) {
                foreach ($this->services->active($website) as $service) {
                    $out[] = ['path' => $service->path(), 'title' => $service->name, 'kind' => 'service', 'text' => trim((string) $service->summary.' '.$service->description)];
                }

                foreach ($this->geo->publishedLocations() as $location) {
                    $out[] = ['path' => $location->path(), 'title' => $location->name, 'kind' => 'location', 'category' => $location->city, 'text' => trim($location->city.' '.$location->region.' '.$location->address_line.' '.$location->geo_description)];
                }
            }

            foreach ($this->contents->categories($website) as $slug => $meta) {
                $out[] = ['path' => '/blog/kategori/'.$slug, 'title' => (string) $meta['name'], 'kind' => 'category', 'category' => (string) $meta['name']];
            }

            return $out;
        });
    }

    /**
     * Bir (silinen/taşınan/404) yol için benzer içerik önerileri. Kaynak özellikleri: verilen anlık görüntü → URL
     * geçmişindeki anlık görüntü → çöpteki (soft delete) içerik → yalnız yol.
     *
     * @param  array{title?: string|null, category?: string|null, tags?: array<int, string>|null, text?: string|null, kind?: string|null}|null  $snapshot
     * @return list<array{path: string, title: string, kind: string, score: int, reasons: list<string>}>
     */
    public function suggest(Website $website, string $path, ?array $snapshot = null, int $limit = 5): array
    {
        $path = $this->history->normalizePath($path);
        $source = ['path' => $path] + ($snapshot ?? $this->sourceFeatures($website, $path));

        return RedirectMatcher::rank($source, $this->candidates($website), $limit);
    }

    /** @return array{title?: string|null, category?: string|null, tags?: array<int, string>|null, text?: string|null, kind?: string|null} */
    private function sourceFeatures(Website $website, string $path): array
    {
        $history = $this->history->historyFor($website, $path)->first();

        if ($history !== null && is_array($history->snapshot) && $history->snapshot !== []) {
            return $history->snapshot;
        }

        // Çöpteki (soft delete) ya da yayından kalkmış içerik hâlâ başlık/kategori/etiket taşır.
        $slug = basename($path);
        $trashed = Content::withTrashed()->where('website_id', $website->id)->where('slug', $slug)->orderByDesc('deleted_at')->first();

        if ($trashed !== null && $trashed->path() === $path) {
            return self::snapshotOf($trashed);
        }

        return ['kind' => str_starts_with($path, '/blog/') ? 'post' : (str_starts_with($path, '/cozum/') ? 'service' : (str_starts_with($path, '/lokasyon/') ? 'location' : 'page'))];
    }

    /** @return array{title: string, category: string|null, tags: array<int, string>, text: string, kind: string} */
    public static function snapshotOf(Content|Service|Location $entity): array
    {
        return match (true) {
            $entity instanceof Content => ['title' => $entity->title, 'category' => $entity->category, 'tags' => array_values(array_map('strval', (array) ($entity->tags ?? []))), 'text' => mb_substr(trim((string) $entity->excerpt.' '.$entity->meta_description.' '.$entity->body), 0, 4000), 'kind' => $entity->kind->value],
            $entity instanceof Service => ['title' => $entity->name, 'category' => null, 'tags' => [], 'text' => mb_substr(trim((string) $entity->summary.' '.$entity->description), 0, 4000), 'kind' => 'service'],
            default => ['title' => $entity->name, 'category' => $entity->city, 'tags' => [], 'text' => mb_substr(trim($entity->city.' '.$entity->region.' '.$entity->address_line.' '.$entity->geo_description), 0, 4000), 'kind' => 'location'],
        };
    }

    // ---- 404 karar zinciri -----------------------------------------------------------

    /**
     * Vitrinde 404 üretecek istek için karar. HTTP katmanı (bootstrap) çağırır; burada istek nesnesi yoktur.
     *
     * @return array{redirect: array{to: string, code: int}|null, suggestions: list<array{path: string, title: string, kind: string, score: int, reasons: list<string>}>}
     */
    public function onNotFound(Website $website, string $path, ?string $referer = null): array
    {
        $path = $this->history->normalizePath($path);
        $none = ['redirect' => null, 'suggestions' => []];

        if ($path === '/' || ! self::looksLikeContentPath($path)) {
            return $none;
        }

        $log = $this->settings->bool($website, 'redirect.log_404') ? $this->logHit($website, $path, $referer) : null;

        if ($log !== null && $log->status === 'ignored') {
            return $none;
        }

        $auto = (int) $this->settings->get($website, 'redirect.auto_threshold');
        $review = (int) $this->settings->get($website, 'redirect.review_threshold');
        $suggestions = $this->suggest($website, $path);
        $best = $suggestions[0] ?? null;

        if ($log !== null && $best !== null && ($log->suggested_path !== $best['path'] || $log->suggested_score !== $best['score'])) {
            $log->forceFill(['suggested_path' => $best['path'], 'suggested_score' => $best['score']])->save();
        }

        // Bekleyen/pasif kayıt varsa admin kararı beklenir; sessizce yönlendirme yok.
        $existing = UrlRedirect::query()->where('website_id', $website->id)->where('from_path', $path)->first();

        if ($existing !== null) {
            return ['redirect' => null, 'suggestions' => $suggestions];
        }

        // Yayından geçici olarak kaldırılmış içerik: kalıcı yönlendirme kurulmaz (yeniden yayınlanacak olabilir); yalnız öneri.
        $known = $this->history->historyFor($website, $path)->first();
        $temporary = $known !== null && $known->reason === 'unpublished';

        if ($temporary) {
            if ($best !== null && $best['score'] >= $review) {
                $this->history->save($website, ['from_path' => $path, 'to_path' => $best['path'], 'source' => 'auto', 'status' => 'pending', 'score' => $best['score'], 'note' => 'Öneri (yayından kaldırılmış): '.implode(' · ', $best['reasons'])]);
            }

            return ['redirect' => null, 'suggestions' => array_values(array_filter($suggestions, fn (array $s) => $s['score'] >= 30))];
        }

        if ($best !== null && $best['score'] >= $auto) {
            $redirect = $this->history->save($website, ['from_path' => $path, 'to_path' => $best['path'], 'source' => 'auto', 'status' => 'active', 'score' => $best['score'], 'note' => 'Otomatik: '.implode(' · ', $best['reasons'])]);

            return ['redirect' => ['to' => $redirect->to_path, 'code' => $redirect->code], 'suggestions' => $suggestions];
        }

        if ($best !== null && $best['score'] >= $review) {
            $this->history->save($website, ['from_path' => $path, 'to_path' => $best['path'], 'source' => 'auto', 'status' => 'pending', 'score' => $best['score'], 'note' => 'Öneri: '.implode(' · ', $best['reasons'])]);

            return ['redirect' => null, 'suggestions' => $suggestions];
        }

        // Eşik altı: yalnız gerçekten var olmuş adres için son çare (ayar); rastgele adres 404 kalır.
        if ($known !== null) {
            $fallback = $this->fallbackFor($website, $known);

            if ($fallback !== null) {
                $redirect = $this->history->save($website, ['from_path' => $path, 'to_path' => $fallback, 'source' => 'fallback', 'status' => 'active', 'score' => $best['score'] ?? 0, 'note' => 'Benzer içerik bulunamadı; üst kategoriye yönlendirildi.']);

                return ['redirect' => ['to' => $redirect->to_path, 'code' => $redirect->code], 'suggestions' => $suggestions];
            }
        }

        return ['redirect' => null, 'suggestions' => array_values(array_filter($suggestions, fn (array $s) => $s['score'] >= 30))];
    }

    private function fallbackFor(Website $website, ContentUrlHistory $known): ?string
    {
        $mode = $this->settings->string($website, 'redirect.fallback');

        if ($mode === 'none') {
            return null;
        }

        if ($mode === 'home') {
            return '/';
        }

        $snapshot = is_array($known->snapshot) ? $known->snapshot : [];
        $kind = (string) ($snapshot['kind'] ?? $known->entity_type);
        $categories = $this->contents->categories($website);

        if ($kind === 'post' || $kind === 'content') {
            $category = RedirectMatcher::ascii((string) ($snapshot['category'] ?? ''));

            foreach ($categories as $slug => $meta) {
                if ($category !== '' && RedirectMatcher::ascii((string) $meta['name']) === $category) {
                    return '/blog/kategori/'.$slug;
                }
            }

            return $this->contents->livePosts($website, 1)->isNotEmpty() ? '/blog' : '/';
        }

        if ($kind === 'service' && $website->is_default && $this->services->active($website)->isNotEmpty()) {
            return '/cozumler';
        }

        if ($kind === 'location' && $website->is_default && $this->geo->publishedLocations()->isNotEmpty()) {
            return '/lokasyonlar';
        }

        return '/';
    }

    /** Uzantılı/bot taraması sayılan yollar (wp-login.php, .env, .xml) yönlendirme ve günlük dışında kalır. */
    public static function looksLikeContentPath(string $path): bool
    {
        return preg_match('~^/[a-z0-9][a-z0-9/_-]{0,290}$~', $path) === 1 && ! str_contains($path, '//');
    }

    private function logHit(Website $website, string $path, ?string $referer): NotFoundLog
    {
        $now = Carbon::now();
        $log = NotFoundLog::query()->where('website_id', $website->id)->where('path', $path)->first();

        if ($log === null) {
            return NotFoundLog::query()->create(['website_id' => $website->id, 'path' => $path, 'hits' => 1, 'first_seen_at' => $now, 'last_seen_at' => $now, 'referer' => $referer !== null ? mb_substr($referer, 0, 500) : null]);
        }

        $log->forceFill(['hits' => $log->hits + 1, 'last_seen_at' => $now, 'referer' => $referer !== null ? mb_substr($referer, 0, 500) : $log->referer])->save();

        return $log;
    }

    // ---- Panel: öneri onayı, 404 günlüğü, istatistik -------------------------------------

    public function approve(User $actor, Website $website, UrlRedirect $redirect, ?string $toPath = null, ?int $code = null): UrlRedirect
    {
        if ((int) $redirect->website_id !== (int) $website->id) {
            throw new DomainException('Yönlendirme bu siteye ait değil.');
        }

        return $this->history->save($website, ['from_path' => $redirect->from_path, 'to_path' => $toPath ?: $redirect->to_path, 'code' => $code, 'status' => 'active', 'source' => $redirect->source === 'auto' ? 'auto' : $redirect->source, 'score' => $redirect->score, 'note' => trim((string) $redirect->note.' Onaylandı.')], $actor, $redirect);
    }

    public function reject(User $actor, Website $website, UrlRedirect $redirect): void
    {
        $this->history->delete($website, $redirect, $actor);
    }

    public function ignoreNotFound(User $actor, Website $website, int $logId, bool $ignore = true): void
    {
        $log = NotFoundLog::query()->where('website_id', $website->id)->find($logId) ?? throw new DomainException('Kayıt bulunamadı.');
        $log->forceFill(['status' => $ignore ? 'ignored' : 'open'])->save();
    }

    /** @return LengthAwarePaginator<int, NotFoundLog> */
    public function notFoundLogs(Website $website, string $status = 'open', ?string $q = null): LengthAwarePaginator
    {
        return NotFoundLog::query()->where('website_id', $website->id)
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($q !== null && $q !== '', fn ($query) => $query->where('path', 'like', '%'.$q.'%'))
            ->orderByDesc('hits')->orderByDesc('last_seen_at')
            ->paginate(30)->withQueryString();
    }

    /** @return LengthAwarePaginator<int, UrlRedirect> */
    public function redirects(Website $website, string $status = 'all', ?string $q = null): LengthAwarePaginator
    {
        return UrlRedirect::query()->with('author')->where('website_id', $website->id)
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($q !== null && $q !== '', fn ($query) => $query->where(fn ($w) => $w->where('from_path', 'like', '%'.$q.'%')->orWhere('to_path', 'like', '%'.$q.'%')))
            ->orderByDesc('updated_at')
            ->paginate(30)->withQueryString();
    }

    public function find(Website $website, int $id): ?UrlRedirect
    {
        return UrlRedirect::query()->where('website_id', $website->id)->find($id);
    }

    /** @return Collection<int, UrlRedirect> */
    public function pending(Website $website): Collection
    {
        return UrlRedirect::query()->where('website_id', $website->id)->where('status', 'pending')->orderByDesc('score')->get();
    }

    /** @return Collection<int, ContentUrlHistory> */
    public function recentHistory(Website $website, int $limit = 50): Collection
    {
        return ContentUrlHistory::query()->where('website_id', $website->id)->orderByDesc('id')->limit($limit)->get();
    }

    /** @return array{redirects: int, pending: int, open_404: int, resolved: int, chains: int, loops: int, history: int} */
    public function stats(Website $website): array
    {
        $map = $this->history->map($website);
        $chains = 0;
        $loops = 0;

        foreach ($map as $from => $row) {
            if (! str_starts_with($row['to'], '/') || ! isset($map[$row['to']])) {
                continue;
            }

            $this->history->finalTarget($website, $row['to'], [$from]) === null ? $loops++ : $chains++;
        }

        return [
            'redirects' => count($map),
            'pending' => UrlRedirect::query()->where('website_id', $website->id)->where('status', 'pending')->count(),
            'open_404' => NotFoundLog::query()->where('website_id', $website->id)->where('status', 'open')->count(),
            'resolved' => NotFoundLog::query()->where('website_id', $website->id)->where('status', 'redirected')->count(),
            'chains' => $chains,
            'loops' => $loops,
            'history' => ContentUrlHistory::query()->where('website_id', $website->id)->count(),
        ];
    }

    // ---- Kırık URL / yönlendirme botu ------------------------------------------------------

    /**
     * Site içi yol çözülüyor mu: sabit rotalar, canlı içerik/hizmet/lokasyon/etkinlik, kategori/etiket sayfaları ya da etkin yönlendirme.
     */
    public function resolves(Website $website, string $path): bool
    {
        $path = $this->history->normalizePath((string) (strtok($path, '?#') ?: $path));

        return isset($this->knownPaths($website)[$path]) || $this->history->resolve($website, $path) !== null;
    }

    /** @return array<string, true> */
    private function knownPaths(Website $website): array
    {
        return $this->cache->memo('redirect.known.'.$website->id, function () use ($website): array {
            $paths = array_fill_keys(self::STATIC_PATHS, true);

            foreach ($this->candidates($website) as $c) {
                $paths[$c['path']] = true;
            }

            foreach (array_keys($this->contents->tags($website)) as $tag) {
                $paths['/blog/etiket/'.$tag] = true;
            }

            if ($website->is_default) {
                foreach ($this->events->upcoming($website) as $event) {
                    $paths[$event->path()] = true;
                }
            }

            return $paths;
        });
    }

    /**
     * Tarama: 404'ler, yönlendirmesiz silinen adresler, zincir/döngü/yanlış hedef, ana sayfaya yönlendirme, kırık iç/dış
     * bağlantı, sitemap'te yönlendirilen adres, canonical uyumsuzluğu, çözülen kayıtlar.
     *
     * @return array{findings: list<array{level: string, kind: string, title: string, detail: string, path: string|null, fix: string|null, suggestion: array{path: string, score: int}|null}>, counts: array<string, int>, probed: int}
     */
    public function scan(Website $website, bool $external = false): array
    {
        $findings = [];
        $map = $this->history->map($website);
        $probed = 0;

        // 1) Açık 404'ler (isabet önceliği).
        foreach (NotFoundLog::query()->where('website_id', $website->id)->where('status', 'open')->orderByDesc('hits')->limit(200)->get() as $log) {
            $best = $this->suggest($website, $log->path, null, 1)[0] ?? null;
            $findings[] = ['level' => $log->hits >= 10 ? 'critical' : 'warning', 'kind' => '404', 'title' => '404: '.$log->path, 'detail' => $log->hits.' isabet · son '.$log->last_seen_at->format('d.m.Y H:i').($log->referer ? ' · referer: '.$log->referer : ''), 'path' => $log->path, 'fix' => null, 'suggestion' => $best !== null ? ['path' => $best['path'], 'score' => $best['score']] : null];
        }

        // 2) Yönlendirmesiz silinen / taşınan adresler (URL geçmişi) hâlâ 404 veriyor mu?
        foreach (ContentUrlHistory::query()->where('website_id', $website->id)->orderByDesc('id')->limit(500)->get()->unique('old_path') as $row) {
            if (isset($map[$row->old_path]) || $this->resolves($website, $row->old_path)) {
                continue;
            }

            $best = $this->suggest($website, $row->old_path, null, 1)[0] ?? null;
            $findings[] = ['level' => 'warning', 'kind' => 'history', 'title' => 'Eski adres yönlendirilmemiş: '.$row->old_path, 'detail' => (ContentUrlHistory::REASONS[$row->reason] ?? $row->reason).' · '.$row->created_at?->format('d.m.Y'), 'path' => $row->old_path, 'fix' => null, 'suggestion' => $best !== null ? ['path' => $best['path'], 'score' => $best['score']] : null];
        }

        // 3) Yönlendirme sağlığı: döngü, zincir, kırık hedef, ana sayfaya yönlendirme.
        foreach ($map as $from => $row) {
            if (str_starts_with($row['to'], '/') && isset($map[$row['to']])) {
                $final = $this->history->finalTarget($website, $row['to'], [$from]);

                if ($final === null) {
                    $findings[] = ['level' => 'critical', 'kind' => 'loop', 'title' => 'Yönlendirme döngüsü: '.$from.' → '.$row['to'], 'detail' => 'Zincir kaynağa geri dönüyor; tarayıcı hata verir.', 'path' => $from, 'fix' => 'delete', 'suggestion' => null];
                } else {
                    $findings[] = ['level' => 'warning', 'kind' => 'chain', 'title' => 'Yönlendirme zinciri: '.$from.' → '.$row['to'].' → … → '.$final, 'detail' => 'Doğrudan '.$from.' → '.$final.' olmalı.', 'path' => $from, 'fix' => 'flatten', 'suggestion' => ['path' => $final, 'score' => 100]];
                }

                continue;
            }

            if (str_starts_with($row['to'], '/') && ! $this->resolves($website, $row['to'])) {
                $best = $this->suggest($website, $from, null, 1)[0] ?? null;
                $findings[] = ['level' => 'critical', 'kind' => 'broken_target', 'title' => 'Yanlış hedef: '.$from.' → '.$row['to'].' (404)', 'detail' => 'Hedef adres artık yok; yönlendirme kırık.', 'path' => $from, 'fix' => null, 'suggestion' => $best !== null ? ['path' => $best['path'], 'score' => $best['score']] : null];
            } elseif ($row['to'] === '/' && $from !== '/') {
                $best = $this->suggest($website, $from, null, 1)[0] ?? null;
                $findings[] = ['level' => 'suggestion', 'kind' => 'to_home', 'title' => 'Ana sayfaya yönlendirme: '.$from, 'detail' => 'Arama motorları bunu soft-404 sayabilir; daha alakalı hedef varsa tercih edin.', 'path' => $from, 'fix' => null, 'suggestion' => $best !== null && $best['score'] >= 30 ? ['path' => $best['path'], 'score' => $best['score']] : null];
            }
        }

        // 4) Bekleyen öneriler.
        foreach ($this->pending($website) as $p) {
            $findings[] = ['level' => 'suggestion', 'kind' => 'pending', 'title' => 'Onay bekleyen öneri: '.$p->from_path.' → '.$p->to_path.' (%'.$p->score.')', 'detail' => (string) $p->note, 'path' => $p->from_path, 'fix' => 'approve', 'suggestion' => ['path' => $p->to_path, 'score' => (int) $p->score]];
        }

        // 5) Kırık iç bağlantılar (canlı içerik gövdeleri) + isteğe bağlı dış bağlantı yoklaması.
        $externalSeen = [];

        foreach ($this->contents->livePages($website)->merge($this->contents->livePosts($website, 1000)) as $content) {
            foreach (self::linksIn((string) $content->body) as $href) {
                if (str_starts_with($href, '/')) {
                    if (! $this->resolves($website, $href)) {
                        $findings[] = ['level' => 'critical', 'kind' => 'internal_link', 'title' => 'Kırık iç bağlantı: '.$href, 'detail' => 'Kaynak: '.$content->title.' ('.$content->path().')', 'path' => $href, 'fix' => null, 'suggestion' => ($b = $this->suggest($website, $href, null, 1)[0] ?? null) !== null ? ['path' => $b['path'], 'score' => $b['score']] : null];
                    } elseif ($this->history->resolve($website, $href) !== null) {
                        $findings[] = ['level' => 'suggestion', 'kind' => 'internal_redirect', 'title' => 'İç bağlantı yönlendirme üzerinden: '.$href, 'detail' => 'Kaynak: '.$content->title.' — bağlantıyı doğrudan '.($this->history->resolve($website, $href)['to'] ?? '').' yapın.', 'path' => $href, 'fix' => null, 'suggestion' => null];
                    }
                } elseif ($external && preg_match('~^https?://~i', $href) === 1 && ! isset($externalSeen[$href]) && $probed < self::MAX_EXTERNAL_PROBES) {
                    $externalSeen[$href] = true;
                    $probed++;

                    try {
                        $status = $this->gateway->probe($href, 5);
                    } catch (Throwable $e) {
                        $status = null;
                    }

                    if ($status === null || $status >= 400) {
                        $findings[] = ['level' => 'warning', 'kind' => 'external_link', 'title' => 'Kırık dış bağlantı: '.$href, 'detail' => 'Kaynak: '.$content->title.' · durum '.($status ?? 'erişilemedi'), 'path' => null, 'fix' => null, 'suggestion' => null];
                    }
                }
            }
        }

        // 6) Sitemap'te yönlendirilen adres olmamalı.
        $base = $this->seo->canonicalBase($website);

        foreach ($this->seo->sitemapEntries($website) as $entry) {
            $path = $this->history->normalizePath(substr((string) $entry['loc'], strlen($base)) ?: '/');

            if (isset($map[$path])) {
                $findings[] = ['level' => 'critical', 'kind' => 'sitemap', 'title' => 'Sitemap\'te yönlendirilen adres: '.$path, 'detail' => 'Yönlendirilen adres sitemap\'e girmemeli.', 'path' => $path, 'fix' => null, 'suggestion' => null];
            }
        }

        // 7) Canonical: içeriğin özel canonical'ı yönlendirilen/olmayan bir yola bakıyorsa.
        foreach ($this->contents->livePages($website)->merge($this->contents->livePosts($website, 1000)) as $content) {
            $canonical = trim((string) ($content->canonical_url ?? ''));

            if ($canonical !== '' && str_starts_with($canonical, '/') && (isset($map[$this->history->normalizePath($canonical)]) || ! $this->resolves($website, $canonical))) {
                $findings[] = ['level' => 'warning', 'kind' => 'canonical', 'title' => 'Canonical uyumsuz: '.$content->path().' → '.$canonical, 'detail' => 'Canonical yönlendirilen ya da olmayan bir adrese bakıyor; kanonik adres canlı olmalı.', 'path' => $content->path(), 'fix' => null, 'suggestion' => null];
            }
        }

        // 8) Düzeltilenler.
        foreach (NotFoundLog::query()->where('website_id', $website->id)->where('status', 'redirected')->orderByDesc('updated_at')->limit(50)->get() as $log) {
            $findings[] = ['level' => 'fixed', 'kind' => 'resolved', 'title' => 'Çözüldü: '.$log->path.' → '.$log->suggested_path, 'detail' => $log->hits.' isabet sonrası yönlendirildi.', 'path' => $log->path, 'fix' => null, 'suggestion' => null];
        }

        $order = ['critical' => 0, 'warning' => 1, 'suggestion' => 2, 'fixed' => 3];
        usort($findings, fn (array $a, array $b) => [$order[$a['level']], $a['title']] <=> [$order[$b['level']], $b['title']]);

        $counts = ['critical' => 0, 'warning' => 0, 'suggestion' => 0, 'fixed' => 0];

        foreach ($findings as $f) {
            $counts[$f['level']]++;
        }

        return ['findings' => $findings, 'counts' => $counts, 'probed' => $probed];
    }

    /** Zinciri düzleştirir (A → son hedef). */
    public function flatten(User $actor, Website $website, UrlRedirect $redirect): UrlRedirect
    {
        $final = $this->history->finalTarget($website, $redirect->to_path, [$redirect->from_path]) ?? throw new DomainException('Döngü: zincir düzleştirilemez, kaydı silin.');

        return $this->history->save($website, ['from_path' => $redirect->from_path, 'to_path' => $final, 'code' => $redirect->code, 'status' => 'active', 'source' => $redirect->source, 'note' => trim((string) $redirect->note.' Zincir düzleştirildi.')], $actor, $redirect);
    }

    /** Markdown/HTML gövdesindeki bağlantı hedefleri. @return list<string> */
    public static function linksIn(string $body): array
    {
        preg_match_all('/\]\(([^)\s]+)\)|href="([^"]+)"/', $body, $m);
        $links = array_values(array_unique(array_filter(array_merge($m[1], $m[2]))));

        return array_values(array_filter($links, fn (string $l) => ! str_starts_with($l, '#') && ! str_starts_with($l, 'mailto:') && ! str_starts_with($l, 'tel:')));
    }

    /**
     * Toplu veri: isabetli yönlendirmeler (panel özeti).
     *
     * @return Collection<int, UrlRedirect>
     */
    public function topRedirects(Website $website, int $limit = 10): Collection
    {
        return UrlRedirect::query()->where('website_id', $website->id)->active()->where('hits', '>', 0)->orderByDesc('hits')->limit($limit)->get();
    }

    /** İstatistik için (dashboard): tüm sitelerde açık 404 + bekleyen öneri. @return array{open_404: int, pending: int} */
    public function globalCounts(): array
    {
        return [
            'open_404' => (int) NotFoundLog::query()->where('status', 'open')->count(),
            'pending' => (int) UrlRedirect::query()->where('status', 'pending')->count(),
        ];
    }

    /** Botu çalıştırır ve sonucu 60 dk önbellekte tutar (panel sekmesi tekrar hesaplamaz). @return array<string, mixed> */
    public function runScan(User $actor, Website $website, bool $external = false): array
    {
        $result = $this->scan($website, $external) + ['ran_at' => Carbon::now()->toIso8601String(), 'external' => $external];
        Cache::put("redirect-scan:{$website->id}", $result, 3600);

        return $result;
    }

    /** Son tarama sonucu (önbellek); yoksa null. @return array<string, mixed>|null */
    public function lastScan(Website $website): ?array
    {
        $cached = Cache::get("redirect-scan:{$website->id}");

        return is_array($cached) ? $cached : null;
    }
}
