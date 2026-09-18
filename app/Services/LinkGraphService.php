<?php

namespace App\Services;

use App\Enums\ContentKind;
use App\Models\Content;
use App\Models\EntityRelation;
use App\Models\Location;
use App\Models\SeoKeyword;
use App\Models\Service;
use App\Models\User;
use App\Models\Website;
use App\Seo\SchemaInspector;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Internal Linking Engine (faz 60c): sitenin iç bağlantı grafı gerçek kaynaklardan kurulur —
 * içerik gövdelerindeki markdown bağlantılar, otomatik anahtar kelime kuralları (links.keywords), menü,
 * hizmet/lokasyon/hizmet × şehir sayfalarının yapısal bağlantıları (Knowledge Graph). Sayfa başına gelen/giden
 * sayı, bağlantı yoğunluğu (100 kelime başına), yetim/kırık sayfa, tür matrisi (Hizmet ↔ Lokasyon ↔ Blog) ve
 * ilgililik puanlı öneriler (varlık ilişkisi, anahtar kelime kümesi, etiket/kategori, bağlanmamış ad geçişi).
 * Manuel bağlantı = anahtar kelime → adres kuralı (SeoSettingsService::set ile; edit modu).
 */
class LinkGraphService
{
    public function __construct(
        private readonly ContentService $contents,
        private readonly SeoService $seo,
        private readonly SeoSettingsService $settings,
        private readonly SchemaInspector $inspector,
        private readonly EntityGraphService $entities,
        private readonly LandingPageService $landing,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function graph(Website $website): array
    {
        $s = $this->settings->for($website);
        $base = $website->baseUrl();
        $canonicalBase = $this->seo->canonicalBase($website);
        $pages = $this->inspector->pages($website);
        $nodes = [];

        foreach ($pages as $page) {
            $nodes[$page['path']] = ['path' => $page['path'], 'label' => $page['label'], 'kind' => $page['kind'], 'in' => 0, 'out' => 0, 'words' => null, 'density' => null, 'broken' => 0];
        }

        $edges = []; // "from|to" => kaynak
        $addEdge = function (string $from, string $to, string $source) use (&$edges, &$nodes): void {
            if ($from === $to || ! isset($nodes[$to])) {
                return;
            }

            $key = $from.'|'.$to;

            if (! isset($edges[$key])) {
                $edges[$key] = ['from' => $from, 'to' => $to, 'source' => $source];
                $nodes[$to]['in']++;

                if (isset($nodes[$from])) {
                    $nodes[$from]['out']++;
                }
            }
        };

        // Menü → sayfalar (her sayfadan erişilir; ana sayfadan sayılır).
        foreach ($this->contents->navigation($website) as $nav) {
            $addEdge('/', $nav->path(), 'menü');
        }

        $live = $this->contents->livePages($website)->merge($this->contents->livePosts($website, 1000));
        $rules = [];

        foreach ((array) $s['links.keywords'] as $row) {
            if (is_array($row) && trim((string) ($row['keyword'] ?? '')) !== '') {
                $to = SeoService::relativePath((string) ($row['url'] ?? ''), $base, $canonicalBase);

                if ($to !== null) {
                    $rules[] = ['keyword' => mb_strtolower(trim((string) $row['keyword'])), 'to' => $to];
                }
            }
        }

        $bodies = [];

        foreach ($live as $content) {
            $from = $content->path();
            $body = (string) $content->body;
            $bodies[$from] = mb_strtolower(strip_tags($body));
            $words = str_word_count(strip_tags($body)) ?: count(preg_split('/\s+/u', trim(strip_tags($body))) ?: []);
            $links = 0;

            preg_match_all('/\]\(([^)\s]+)\)/', $body, $matches);

            foreach ($matches[1] as $href) {
                $to = SeoService::relativePath($href, $base, $canonicalBase);

                if ($to === null) {
                    continue;
                }

                $links++;

                if (isset($nodes[$to])) {
                    $addEdge($from, $to, 'gövde');
                } elseif (isset($nodes[$from])) {
                    $nodes[$from]['broken']++;
                }
            }

            if ($s['links.auto_enabled']) {
                foreach ($rules as $rule) {
                    if (str_contains($bodies[$from], $rule['keyword'])) {
                        $addEdge($from, $rule['to'], 'otomatik kural');
                    }
                }
            }

            if ($content->parent_slug && $content->kind === ContentKind::PAGE) {
                $addEdge('/'.$content->parent_slug, $from, 'alt sayfa listesi');
            }

            if (isset($nodes[$from])) {
                $nodes[$from]['words'] = $words;
                $nodes[$from]['density'] = $words > 0 ? round($links / $words * 100, 2) : null;
            }
        }

        // Yapısal bağlantılar (Ofisvio vitrini): hizmet ↔ lokasyon, hizmet × şehir, Knowledge Graph yazıları.
        if ($website->is_default) {
            foreach (Service::query()->active()->with('locations')->get() as $service) {
                $addEdge('/cozumler', $service->path(), 'liste');

                foreach ($service->locations->where('is_published', true) as $location) {
                    $addEdge($service->path(), $location->path(), 'hizmet → şube');
                    $addEdge($location->path(), $service->path(), 'şube → hizmet');
                }

                foreach ((array) ($service->answers['related_services'] ?? []) as $relatedId) {
                    $related = Service::query()->find((int) $relatedId);

                    if ($related !== null) {
                        $addEdge($service->path(), $related->path(), 'ilgili hizmet');
                    }
                }

                foreach ($this->entities->contentsAbout($website, 'service', $service->id) as $article) {
                    $addEdge($service->path(), $article->path(), 'Knowledge Graph');
                    $addEdge($article->path(), $service->path(), 'Knowledge Graph');
                }
            }

            foreach (Location::query()->published()->get() as $location) {
                $addEdge('/lokasyonlar', $location->path(), 'liste');

                foreach ($this->entities->contentsAbout($website, 'location', $location->id) as $article) {
                    $addEdge($location->path(), $article->path(), 'Knowledge Graph');
                    $addEdge($article->path(), $location->path(), 'Knowledge Graph');
                }
            }

            foreach ($this->landing->live($website) as $landing) {
                $addEdge($landing->service->path(), $landing->path(), 'şehir sayfası');
                $addEdge($landing->location->path(), $landing->path(), 'şehir sayfası');
                $addEdge($landing->path(), $landing->service->path(), 'şehir sayfası');
                $addEdge($landing->path(), $landing->location->path(), 'şehir sayfası');
            }
        }

        // Blog listesi → yazılar (kategori/etiket sayfaları liste kabul edilir).
        foreach ($this->contents->livePosts($website, 1000) as $post) {
            $addEdge('/blog', $post->path(), 'liste');
        }

        $orphans = array_values(array_filter($nodes, fn (array $n) => $n['in'] === 0 && $n['path'] !== '/' && ! in_array($n['kind'], ['static', 'listing'], true)));
        $matrix = $this->matrix($nodes, $edges);
        $suggestions = $this->suggestions($website, $live, $nodes, $edges, $bodies);

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'orphans' => $orphans,
            'matrix' => $matrix,
            'suggestions' => $suggestions,
            'rules' => $rules,
            'auto_enabled' => (bool) $s['links.auto_enabled'],
            'counts' => ['pages' => count($nodes), 'edges' => count($edges), 'orphans' => count($orphans), 'broken' => array_sum(array_column($nodes, 'broken')), 'avg_density' => self::avgDensity($nodes)],
        ];
    }

    /** Manuel bağlantı kuralı: anahtar kelime → adres (links.keywords satırı). */
    public function addRule(User $actor, Website $website, string $keyword, string $url): void
    {
        $keyword = trim($keyword);
        $url = trim($url);

        if (mb_strlen($keyword) < 2 || preg_match('#^(/|https?://)[^\s]*$#', $url) !== 1) {
            throw new DomainException('Anahtar kelime ve geçerli bir adres gerekli.');
        }

        $rows = array_values(array_filter((array) $this->settings->get($website, 'links.keywords'), 'is_array'));

        foreach ($rows as $row) {
            if (mb_strtolower(trim((string) ($row['keyword'] ?? ''))) === mb_strtolower($keyword)) {
                throw new DomainException('Bu anahtar kelime için kural zaten var.');
            }
        }

        $rows[] = ['keyword' => mb_substr($keyword, 0, 80), 'url' => mb_substr($url, 0, 300)];
        $this->settings->set($actor, $website, ['links.keywords' => $rows], 'İç bağlantı ekranından manuel kural');
    }

    public function removeRule(User $actor, Website $website, string $keyword): void
    {
        $rows = array_values(array_filter((array) $this->settings->get($website, 'links.keywords'), fn ($row) => is_array($row) && mb_strtolower(trim((string) ($row['keyword'] ?? ''))) !== mb_strtolower(trim($keyword))));
        $this->settings->set($actor, $website, ['links.keywords' => $rows], 'İç bağlantı ekranından kural silme');
    }

    /**
     * Tür matrisi: kaynak tür → hedef tür bağlantı sayısı.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  array<string, array{from: string, to: string, source: string}>  $edges
     * @return array<string, array<string, int>>
     */
    private function matrix(array $nodes, array $edges): array
    {
        $kinds = ['service', 'location', 'post', 'page', 'landing'];
        $matrix = [];

        foreach ($kinds as $a) {
            foreach ($kinds as $b) {
                $matrix[$a][$b] = 0;
            }
        }

        foreach ($edges as $edge) {
            $from = $nodes[$edge['from']]['kind'] ?? null;
            $to = $nodes[$edge['to']]['kind'] ?? null;

            if ($from !== null && $to !== null && isset($matrix[$from][$to])) {
                $matrix[$from][$to]++;
            }
        }

        return $matrix;
    }

    /**
     * Öneriler: her yazı/sayfa için henüz bağlanmamış en ilgili hedefler (puan + gerekçe + çapa metni).
     *
     * @param  Collection<int, Content>  $live
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  array<string, array{from: string, to: string, source: string}>  $edges
     * @param  array<string, string>  $bodies
     * @return list<array{from: array{path: string, label: string}, to: array{path: string, label: string, kind: string}, score: int, reasons: list<string>, anchor: string}>
     */
    private function suggestions(Website $website, $live, array $nodes, array $edges, array $bodies): array
    {
        $relations = EntityRelation::query()->where('website_id', $website->id)->where('from_type', 'content')->get();
        $keywords = SeoKeyword::query()->where('website_id', $website->id)->whereNotNull('cluster')->whereNotNull('target_path')->get();
        $clusterByPath = [];

        foreach ($keywords as $k) {
            $clusterByPath[$k->target_path][] = $k->cluster;
        }

        $targets = [];

        if ($website->is_default) {
            foreach (Service::query()->active()->get() as $service) {
                $targets[] = ['path' => $service->path(), 'label' => $service->name, 'kind' => 'service', 'names' => [$service->name], 'tags' => [], 'category' => null, 'id' => $service->id];
            }
            foreach (Location::query()->published()->get() as $location) {
                $targets[] = ['path' => $location->path(), 'label' => $location->name, 'kind' => 'location', 'names' => array_values(array_unique(array_filter([$location->name, $location->city]))), 'tags' => [], 'category' => null, 'id' => $location->id];
            }
        }

        foreach ($live as $content) {
            $targets[] = ['path' => $content->path(), 'label' => $content->title, 'kind' => $content->kind->value, 'names' => [$content->title], 'tags' => array_map('mb_strtolower', (array) ($content->tags ?? [])), 'category' => $content->category, 'id' => $content->id];
        }

        $out = [];

        foreach ($live as $content) {
            $from = $content->path();
            $body = $bodies[$from] ?? '';
            $myTags = array_map('mb_strtolower', (array) ($content->tags ?? []));
            $myClusters = $clusterByPath[$from] ?? [];
            $mine = $relations->where('from_id', (string) $content->id);
            $candidates = [];

            foreach ($targets as $target) {
                if ($target['path'] === $from || isset($edges[$from.'|'.$target['path']])) {
                    continue;
                }

                $score = 0;
                $reasons = [];
                $anchor = $target['label'];

                if ($target['kind'] === 'service' || $target['kind'] === 'location') {
                    if ($mine->where('to_type', $target['kind'])->where('to_id', (string) $target['id'])->isNotEmpty()) {
                        $score += 5;
                        $reasons[] = 'Knowledge Graph ilişkisi';
                    }
                }

                foreach ($target['names'] as $name) {
                    $needle = mb_strtolower($name);

                    if (mb_strlen($needle) >= 4 && str_contains($body, $needle)) {
                        $score += 4;
                        $reasons[] = 'metinde geçiyor, bağlanmamış: "'.$name.'"';
                        $anchor = $name;
                        break;
                    }
                }

                $shared = array_intersect($myClusters, $clusterByPath[$target['path']] ?? []);

                if ($shared !== []) {
                    $score += 3;
                    $reasons[] = 'aynı konu kümesi: '.implode(', ', array_unique($shared));
                }

                $sharedTags = array_intersect($myTags, $target['tags']);

                if ($sharedTags !== []) {
                    $score += 2 * count($sharedTags);
                    $reasons[] = 'ortak etiket: '.implode(', ', $sharedTags);
                }

                if ($content->category !== null && $content->category === $target['category']) {
                    $score += 2;
                    $reasons[] = 'aynı kategori';
                }

                if ($score >= 3) {
                    $candidates[] = ['from' => ['path' => $from, 'label' => $content->title], 'to' => ['path' => $target['path'], 'label' => $target['label'], 'kind' => $target['kind']], 'score' => $score, 'reasons' => $reasons, 'anchor' => $anchor];
                }
            }

            usort($candidates, fn (array $a, array $b) => $b['score'] <=> $a['score']);
            $out = array_merge($out, array_slice($candidates, 0, 5));
        }

        usort($out, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return $out;
    }

    /** @param  array<string, array<string, mixed>>  $nodes */
    private static function avgDensity(array $nodes): ?float
    {
        $values = array_values(array_filter(array_column($nodes, 'density'), fn ($v) => $v !== null));

        return $values === [] ? null : round(array_sum($values) / count($values), 2);
    }
}
