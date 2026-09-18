<?php

namespace App\Services;

use App\Enums\ContentKind;
use App\Models\Content;
use App\Models\EntityRelation;
use App\Models\Location;
use App\Models\Service;
use App\Models\User;
use App\Models\Website;
use App\Seo\GeoAnswers;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Entity / Knowledge Graph (faz 60b): sitenin varlıkları (marka, hizmetler, lokasyonlar, yazarlar, SSS) ve
 * aralarındaki ilişkiler tek yerden. Service ↔ Location pivot'tan (location_service), Article → Service/Location
 * ve Topic → Entity `entity_relations`'tan okunur; yalnız bu servis yazar (audit + önbellek sürümü).
 * Uydurma varlık yok: yalnız DB'de var olan hizmet/şube/yazı/yazar listelenir.
 */
class EntityGraphService
{
    public function __construct(
        private readonly ContentService $contents,
        private readonly ServiceService $services,
        private readonly GeoService $geo,
        private readonly AuditService $audit,
        private readonly ContentCache $cache,
    ) {}

    /**
     * Yazının ilişkilerini yeniden yazar (Article → Service "about", Article → Location "about").
     *
     * @param  list<int>  $serviceIds
     * @param  list<int>  $locationIds
     */
    public function syncContentRelations(User $actor, Website $website, Content $content, array $serviceIds, array $locationIds): void
    {
        if ($content->website_id !== $website->id) {
            throw new DomainException('İçerik bu siteye ait değil.');
        }

        $validServices = Service::query()->whereIn('id', $serviceIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $validLocations = Location::query()->whereIn('id', $locationIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $before = $this->relationsFrom($website, 'content', (string) $content->id);

        EntityRelation::query()->where('website_id', $website->id)->where('from_type', 'content')->where('from_id', (string) $content->id)->whereIn('to_type', ['service', 'location'])->delete();

        foreach ($validServices as $id) {
            EntityRelation::query()->create(['website_id' => $website->id, 'from_type' => 'content', 'from_id' => (string) $content->id, 'to_type' => 'service', 'to_id' => (string) $id, 'relation' => 'about']);
        }

        foreach ($validLocations as $id) {
            EntityRelation::query()->create(['website_id' => $website->id, 'from_type' => 'content', 'from_id' => (string) $content->id, 'to_type' => 'location', 'to_id' => (string) $id, 'relation' => 'about']);
        }

        $this->cache->invalidate($website);
        $this->audit->record($actor, 'entity.relations_synced', 'content', $content->id, $before, ['services' => $validServices, 'locations' => $validLocations]);
    }

    /**
     * Konu → varlık ilişkisi (Topic → Service/Location/Content).
     */
    public function linkTopic(User $actor, Website $website, string $topic, string $toType, int $toId): EntityRelation
    {
        $topic = Str::slug($topic);

        if ($topic === '' || ! in_array($toType, ['service', 'location', 'content'], true)) {
            throw new DomainException('Konu adı ve hedef türü gerekli.');
        }

        if (! $this->entityExists($website, $toType, $toId)) {
            throw new DomainException('Hedef varlık bulunamadı.');
        }

        $row = EntityRelation::query()->firstOrCreate(['website_id' => $website->id, 'from_type' => 'topic', 'from_id' => $topic, 'to_type' => $toType, 'to_id' => (string) $toId, 'relation' => 'about']);
        $this->cache->invalidate($website);
        $this->audit->record($actor, 'entity.topic_linked', 'website', $website->id, [], ['topic' => $topic, 'to' => $toType.':'.$toId]);

        return $row;
    }

    public function unlink(User $actor, Website $website, int $relationId): void
    {
        $row = EntityRelation::query()->where('website_id', $website->id)->find($relationId);

        if ($row === null) {
            throw new DomainException('İlişki bulunamadı.');
        }

        $row->delete();
        $this->cache->invalidate($website);
        $this->audit->record($actor, 'entity.relation_removed', 'website', $website->id, $row->toArray(), []);
    }

    /**
     * Bir varlığa bağlı yayındaki yazılar (hizmet/lokasyon sayfasında "İlgili yazılar").
     *
     * @return Collection<int, Content>
     */
    public function contentsAbout(Website $website, string $type, int $id): Collection
    {
        $ids = $this->cache->remember($website, "entity:contents:{$type}:{$id}", fn () => EntityRelation::query()
            ->where('website_id', $website->id)->where('from_type', 'content')->where('to_type', $type)->where('to_id', (string) $id)
            ->pluck('from_id')->map(fn ($v) => (int) $v)->all());

        if ($ids === []) {
            return new Collection;
        }

        return Content::query()->where('website_id', $website->id)->whereIn('id', $ids)->live()->orderByDesc('published_at')->limit(6)->get();
    }

    /**
     * Yazının bağlı olduğu hizmet/lokasyonlar (yazı sayfasında "İlgili hizmetler / lokasyonlar").
     *
     * @return array{services: Collection<int, Service>, locations: Collection<int, Location>}
     */
    public function entitiesOf(Website $website, Content $content): array
    {
        $rows = $this->cache->remember($website, 'entity:of:'.$content->id, fn () => EntityRelation::query()
            ->where('website_id', $website->id)->where('from_type', 'content')->where('from_id', (string) $content->id)
            ->get(['to_type', 'to_id'])->map(fn (EntityRelation $r) => ['type' => $r->to_type, 'id' => (int) $r->to_id])->all());
        $serviceIds = array_map(fn (array $r) => $r['id'], array_filter($rows, fn (array $r) => $r['type'] === 'service'));
        $locationIds = array_map(fn (array $r) => $r['id'], array_filter($rows, fn (array $r) => $r['type'] === 'location'));

        return [
            'services' => $serviceIds === [] ? new Collection : Service::query()->active()->whereIn('id', $serviceIds)->get(),
            'locations' => $locationIds === [] ? new Collection : Location::query()->where('is_published', true)->where('is_active', true)->whereIn('id', $locationIds)->orderBy('name')->get(),
        ];
    }

    /**
     * Graf özeti (panel): varlıklar, ilişkiler, boşluklar.
     *
     * @return array<string, mixed>
     */
    public function graph(Website $website): array
    {
        $services = $website->is_default ? $this->services->active($website)->load('locations') : new Collection;
        $locations = $website->is_default ? $this->geo->publishedLocations()->load('services') : new Collection;
        $posts = $this->contents->livePosts($website, 1000);
        $pages = $this->contents->livePages($website);
        $relations = EntityRelation::query()->where('website_id', $website->id)->orderBy('from_type')->orderBy('from_id')->get();
        $byContent = [];
        $topics = [];

        foreach ($relations as $r) {
            if ($r->from_type === 'content') {
                $byContent[(int) $r->from_id][] = $r;
            } elseif ($r->from_type === 'topic') {
                $topics[$r->from_id][] = $r;
            }
        }

        $authors = [];

        foreach ($posts as $post) {
            if ($post->author !== null) {
                $authors[$post->author->id] ??= ['name' => $post->author->name, 'posts' => 0];
                $authors[$post->author->id]['posts']++;
            }
        }

        $faqCount = 0;

        foreach ($services as $service) {
            $faqCount += count($service->faqPairs());
        }

        foreach ($posts->merge($pages) as $content) {
            $faqCount += count(SeoService::faqPairs((string) $content->body));
        }

        $unlinked = $posts->filter(fn (Content $p) => ! isset($byContent[$p->id]))->values();

        return [
            'brand' => $website->brand() + ['same_as' => array_values(array_filter((array) ($website->same_as ?? [])))],
            'services' => $services->map(fn (Service $s) => ['model' => $s, 'locations' => $s->locations->where('is_published', true)->pluck('name')->all(), 'filled' => GeoAnswers::filled($s->answers), 'articles' => $relations->where('to_type', 'service')->where('to_id', (string) $s->id)->count()])->values()->all(),
            'locations' => $locations->map(fn (Location $l) => ['model' => $l, 'services' => $l->services->pluck('name')->all(), 'articles' => $relations->where('to_type', 'location')->where('to_id', (string) $l->id)->count()])->values()->all(),
            'authors' => array_values($authors),
            'faq_count' => $faqCount,
            'relations' => $relations,
            'by_content' => $byContent,
            'topics' => $topics,
            'posts' => $posts,
            'unlinked_posts' => $unlinked,
            'labels' => $this->labels($website, $relations),
        ];
    }

    /**
     * İlişki satırlarındaki kimlikler için ad haritası (tek sorgu/tür).
     *
     * @param  Collection<int, EntityRelation>  $relations
     * @return array<string, string> "type:id" => ad
     */
    private function labels(Website $website, Collection $relations): array
    {
        $ids = ['service' => [], 'location' => [], 'content' => []];

        foreach ($relations as $r) {
            foreach ([[$r->from_type, $r->from_id], [$r->to_type, $r->to_id]] as [$type, $id]) {
                if (isset($ids[$type])) {
                    $ids[$type][] = (int) $id;
                }
            }
        }

        $labels = [];

        foreach (Service::query()->whereIn('id', array_unique($ids['service']))->get(['id', 'name']) as $s) {
            $labels['service:'.$s->id] = $s->name;
        }
        foreach (Location::query()->whereIn('id', array_unique($ids['location']))->get(['id', 'name']) as $l) {
            $labels['location:'.$l->id] = $l->name;
        }
        foreach (Content::query()->where('website_id', $website->id)->whereIn('id', array_unique($ids['content']))->get(['id', 'title']) as $c) {
            $labels['content:'.$c->id] = $c->title;
        }

        return $labels;
    }

    /** @return array<string, mixed> */
    private function relationsFrom(Website $website, string $type, string $id): array
    {
        return EntityRelation::query()->where('website_id', $website->id)->where('from_type', $type)->where('from_id', $id)->get(['to_type', 'to_id', 'relation'])->toArray();
    }

    private function entityExists(Website $website, string $type, int $id): bool
    {
        return match ($type) {
            'service' => Service::query()->whereKey($id)->exists(),
            'location' => Location::query()->whereKey($id)->exists(),
            'content' => Content::query()->where('website_id', $website->id)->whereKey($id)->whereIn('kind', [ContentKind::POST->value, ContentKind::PAGE->value])->exists(),
            default => false,
        };
    }
}
