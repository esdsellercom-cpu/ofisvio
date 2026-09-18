<?php

namespace App\Services;

use App\Models\Location;
use App\Models\SeoLandingPage;
use App\Models\Service;
use App\Models\User;
use App\Models\Website;
use App\Support\TurkishSuffix;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Programatik SEO (faz 60b): hizmet × şehir sayfaları (/{hizmet}/{sehir}). Kural: her sayfa TEK TEK oluşturulur,
 * toplu üretim yok; yayın yalnız kalite denetimi geçince (benzersiz giriş metni ≥ MIN_INTRO karakter, aynı hizmetin
 * diğer şehir sayfalarına ve hizmet açıklamasına benzerlik < MAX_SIMILARITY, başlık tekil, meta açıklama var).
 * Şablon değişkenleri {hizmet} {sehir} {sehir_da} {sube} {adres} yalnız DB'deki değerlerle dolar. Yazma yalnız burada.
 */
class LandingPageService
{
    public const MIN_INTRO = 400;

    public const MAX_SIMILARITY = 0.5;

    public function __construct(
        private readonly AuditService $audit,
        private readonly ContentCache $cache,
        private readonly UrlHistoryService $urls,
    ) {}

    /** @return Collection<int, SeoLandingPage> */
    public function all(Website $website): Collection
    {
        return SeoLandingPage::query()->where('website_id', $website->id)->with(['service', 'location'])->orderBy('service_id')->orderBy('city_slug')->get();
    }

    public function find(Website $website, int $id): ?SeoLandingPage
    {
        return SeoLandingPage::query()->where('website_id', $website->id)->with(['service', 'location'])->find($id);
    }

    /** Vitrin: yayındaki sayfa (hizmet slug + şehir slug). */
    public function findLive(?Website $website, string $serviceSlug, string $citySlug): ?SeoLandingPage
    {
        if ($website === null || ! $website->is_default) {
            return null;
        }

        $id = $this->cache->remember($website, 'landing:'.sha1($serviceSlug.'/'.$citySlug), function () use ($website, $serviceSlug, $citySlug) {
            $service = Service::query()->where('slug', $serviceSlug)->where('is_active', true)->first();

            if ($service === null) {
                return 0;
            }

            return (int) (SeoLandingPage::query()->where('website_id', $website->id)->where('service_id', $service->id)->where('city_slug', $citySlug)->live()
                ->whereHas('location', fn ($q) => $q->where('is_published', true)->where('is_active', true))->value('id') ?? 0);
        });

        return $id > 0 ? SeoLandingPage::query()->with(['service', 'location.cover'])->find($id) : null;
    }

    /**
     * Yayındaki sayfalar (sitemap, hizmet/lokasyon sayfası bağlantıları).
     *
     * @return Collection<int, SeoLandingPage>
     */
    public function live(Website $website, ?int $serviceId = null, ?int $locationId = null): Collection
    {
        if (! $website->is_default) {
            return new Collection;
        }

        $ids = $this->cache->remember($website, 'landing:live', fn () => SeoLandingPage::query()->where('website_id', $website->id)->live()
            ->whereHas('service', fn ($q) => $q->where('is_active', true))
            ->whereHas('location', fn ($q) => $q->where('is_published', true)->where('is_active', true))
            ->pluck('id')->map(fn ($id) => (int) $id)->all());

        if ($ids === []) {
            return new Collection;
        }

        $query = SeoLandingPage::query()->whereIn('id', $ids)->with(['service', 'location'])->orderBy('title');

        if ($serviceId !== null) {
            $query->where('service_id', $serviceId);
        }

        if ($locationId !== null) {
            $query->where('location_id', $locationId);
        }

        return $query->get();
    }

    /**
     * Tek sayfa oluşturur (hizmet + lokasyon benzersiz). Taslak olarak; kalite denetimi kaydedilir.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, Website $website, array $data): SeoLandingPage
    {
        if (! $website->is_default) {
            throw new DomainException('Programatik sayfalar yalnız Ofisvio vitrininde.');
        }

        $service = Service::query()->find((int) ($data['service_id'] ?? 0));
        $location = Location::query()->find((int) ($data['location_id'] ?? 0));

        if ($service === null || $location === null) {
            throw new DomainException('Hizmet ve lokasyon seçin.');
        }

        if (SeoLandingPage::query()->where('website_id', $website->id)->where('service_id', $service->id)->where('location_id', $location->id)->exists()) {
            throw new DomainException('Bu hizmet × lokasyon sayfası zaten var; düzenleyin.');
        }

        $page = new SeoLandingPage(['website_id' => $website->id, 'service_id' => $service->id, 'location_id' => $location->id, 'city_slug' => Str::slug($location->city) ?: $location->slug, 'status' => SeoLandingPage::STATUS_DRAFT, 'created_by' => $actor->id]);
        $page->fill($this->attributes($data, $service, $location));
        $page->updated_by = $actor->id;
        $page->quality = $this->quality($page);
        $page->save();
        $this->audit->record($actor, 'landing.created', 'seo_landing_page', $page->id, [], $page->toArray());
        $this->cache->invalidate($website);

        return $page->load(['service', 'location']);
    }

    /** @param  array<string, mixed>  $data */
    public function update(User $actor, Website $website, SeoLandingPage $page, array $data): SeoLandingPage
    {
        $before = $page->toArray();
        $page->fill($this->attributes($data, $page->service, $page->location));
        $page->updated_by = $actor->id;
        $page->quality = $this->quality($page);

        // Yayındaki sayfa denetimi geçemez hale geldiyse yayında kalmaz (kopya/zayıf sayfa yayınlanmaz).
        if ($page->status === SeoLandingPage::STATUS_PUBLISHED && ! $page->quality['ok']) {
            $page->status = SeoLandingPage::STATUS_DRAFT;
        }

        $page->save();
        $this->audit->record($actor, 'landing.updated', 'seo_landing_page', $page->id, $before, $page->toArray());
        $this->cache->invalidate($website);

        return $page;
    }

    /** Yayın: yalnız kalite denetimi geçerse. */
    public function publish(User $actor, Website $website, SeoLandingPage $page): SeoLandingPage
    {
        $quality = $this->quality($page);
        $page->quality = $quality;

        if (! $quality['ok']) {
            $page->save();

            throw new DomainException('Yayınlanamaz — kalite denetimi: '.implode(' · ', $quality['issues']));
        }

        $page->status = SeoLandingPage::STATUS_PUBLISHED;
        $page->published_at ??= now();
        $page->updated_by = $actor->id;
        $page->save();
        $this->urls->recordRestore($website, $page->path());
        $this->audit->record($actor, 'landing.published', 'seo_landing_page', $page->id, [], ['path' => $page->path()]);
        $this->cache->invalidate($website);

        return $page;
    }

    public function unpublish(User $actor, Website $website, SeoLandingPage $page): SeoLandingPage
    {
        $page->status = SeoLandingPage::STATUS_DRAFT;
        $page->updated_by = $actor->id;
        $page->save();
        $this->audit->record($actor, 'landing.unpublished', 'seo_landing_page', $page->id, [], ['path' => $page->path()]);
        $this->cache->invalidate($website);

        return $page;
    }

    /** Silme: adres URL geçmişine düşer (faz 54 kancası); isteğe bağlı yönlendirme hedefi. */
    public function delete(User $actor, Website $website, SeoLandingPage $page, ?string $redirectTo = null): void
    {
        $path = $page->path();
        $snapshot = ['title' => $page->title, 'excerpt' => $page->meta_description, 'kind' => 'landing'];
        $page->delete();
        $this->urls->recordDeletion($website, 'seo_landing_page', $page->id, $path, $snapshot, $redirectTo, $actor);
        $this->audit->record($actor, 'landing.deleted', 'seo_landing_page', $page->id, ['path' => $path], []);
        $this->cache->invalidate($website);
    }

    /**
     * Kalite denetimi — yayın kapısı ve Command Center bulgusu.
     *
     * @return array{ok: bool, score: int, issues: list<string>, similarity: float, intro_chars: int, checked_at: string}
     */
    public function quality(SeoLandingPage $page): array
    {
        $issues = [];
        $intro = trim($page->intro);
        $chars = mb_strlen($intro);

        if ($chars < self::MIN_INTRO) {
            $issues[] = 'Benzersiz giriş metni '.$chars.' karakter (en az '.self::MIN_INTRO.').';
        }

        if (trim((string) $page->meta_description) === '') {
            $issues[] = 'Meta açıklama yok.';
        }

        if (trim($page->title) === '') {
            $issues[] = 'Başlık yok.';
        }

        $service = $page->service;
        $similarity = 0.0;

        if ($service !== null) {
            $own = self::shingles($intro.' '.(string) $page->body);
            $texts = [(string) $service->summary.' '.(string) $service->description];

            foreach (SeoLandingPage::query()->where('website_id', $page->website_id)->where('service_id', $service->id)->where('id', '!=', $page->id ?? 0)->get(['intro', 'body', 'title']) as $other) {
                $texts[] = $other->intro.' '.(string) $other->body;

                if (mb_strtolower(trim($other->title)) === mb_strtolower(trim($page->title))) {
                    $issues[] = 'Başlık başka bir şehir sayfasıyla aynı.';
                }
            }

            foreach ($texts as $text) {
                $similarity = max($similarity, self::jaccard($own, self::shingles($text)));
            }

            if ($similarity >= self::MAX_SIMILARITY) {
                $issues[] = 'İçerik hizmet açıklamasına ya da başka şehir sayfasına %'.(int) round($similarity * 100).' benziyor (sınır %'.(int) (self::MAX_SIMILARITY * 100).').';
            }
        }

        if ($page->location !== null && ! $page->location->is_published) {
            $issues[] = 'Lokasyon yayında değil.';
        }

        $score = max(0, 100 - count($issues) * 25);

        return ['ok' => $issues === [], 'score' => $score, 'issues' => array_values(array_unique($issues)), 'similarity' => round($similarity, 2), 'intro_chars' => $chars, 'checked_at' => now()->toIso8601String()];
    }

    /**
     * Şablon değişkenleri: yalnız DB'deki değerler.
     *
     * @return array<string, string>
     */
    public static function variables(Service $service, Location $location): array
    {
        return [
            '{hizmet}' => $service->name,
            '{sehir}' => (string) $location->city,
            '{sehir_da}' => TurkishSuffix::locative((string) $location->city),
            '{sube}' => $location->name,
            '{adres}' => (string) ($location->address_line ?? ''),
            '{ilce}' => (string) ($location->district ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, Service $service, Location $location): array
    {
        $vars = self::variables($service, $location);
        $fill = fn (string $text) => trim(strtr($text, $vars));
        $faq = [];

        foreach ((array) ($data['faq'] ?? []) as $row) {
            if (is_array($row) && trim((string) ($row['q'] ?? '')) !== '' && trim((string) ($row['a'] ?? '')) !== '') {
                $faq[] = ['q' => mb_substr($fill((string) $row['q']), 0, 200), 'a' => mb_substr($fill((string) $row['a']), 0, 1000)];
            }
        }

        return [
            'title' => mb_substr($fill((string) ($data['title'] ?? '')) ?: $service->name.' '.$location->city, 0, 120),
            'meta_description' => mb_substr($fill((string) ($data['meta_description'] ?? '')), 0, 200) ?: null,
            'intro' => $fill((string) ($data['intro'] ?? '')),
            'body' => $fill((string) ($data['body'] ?? '')) ?: null,
            'faq' => array_slice($faq, 0, 10),
            'is_indexable' => (bool) ($data['is_indexable'] ?? true),
        ];
    }

    /** @return array<string, true> */
    private static function shingles(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(strip_tags($text))) ?: [];
        $words = array_values(array_filter($words, fn (string $w) => $w !== ''));
        $set = [];

        for ($k = 0; $k + 4 <= count($words); $k++) {
            $set[implode(' ', array_slice($words, $k, 4))] = true;
        }

        return $set;
    }

    /**
     * @param  array<string, true>  $a
     * @param  array<string, true>  $b
     */
    private static function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $intersection = count(array_intersect_key($a, $b));

        return $intersection / (count($a) + count($b) - $intersection);
    }
}
