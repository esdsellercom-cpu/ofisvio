<?php

namespace App\Services;

use App\Content\GeoSuggester;
use App\Content\SeoAnalyzer;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Events\ContentPublicationChanged;
use App\Models\Content;
use App\Models\ContentDraft;
use App\Models\ContentRevision;
use App\Models\Media;
use App\Models\User;
use App\Models\Website;
use Carbon\CarbonImmutable;
use Closure;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CMS Core (faz 9) — içerik yaşam döngüsü.
 *
 * TEK YAZAR KURALI: contents.status kolonuna yalnızca bu servis yazar
 * (transition / publishScheduled). Yetki KARARI route'tadır (her geçiş kendi
 * izniyle ayrı route); burada yalnızca iş kuralı:
 *   - requires_approval içerik APPROVED'a uğramadan yayınlanamaz/zamanlanamaz
 *   - yalnızca DRAFT düzenlenir; canlı metin sessizce değişmez, önce
 *     taslağa alınır (yayından kalkar) ve akışı yeniden geçer
 *   - her kayıt bir revizyon bırakır (denetim izi)
 */
class ContentService
{
    /** Sayfa/yazı başına seçilebilen şema türleri (faz 48). */
    public const SCHEMA_TYPES = ['WebPage', 'Article', 'FAQPage', 'BreadcrumbList', 'Organization', 'LocalBusiness', 'Service'];

    private const WORDS_PER_MINUTE = 200;

    public function __construct(private readonly ContentCache $cache) {}

    // -----------------------------------------------------------------
    // Okuma (vitrin)
    // -----------------------------------------------------------------

    /**
     * Varsayılan website (Ofisvio vitrini). Panel için zorunludur: yoksa
     * seeder çalıştırılmamıştır ve editör anlamlı bir şey yapamaz.
     */
    public function defaultWebsite(): Website
    {
        $website = $this->defaultWebsiteOrNull();

        if ($website === null) {
            throw new DomainException('Varsayılan website tanımlı değil — WebsiteSeeder çalıştırılmalı.');
        }

        return $website;
    }

    /**
     * Vitrin için: website yoksa vitrin ÇÖKMEZ, CMS bölümleri boş/404 döner.
     * Okuyucu metodlar null kabul eder.
     */
    public function defaultWebsiteOrNull(): ?Website
    {
        return Website::query()->default()->first();
    }

    public function websiteById(int $id): Website
    {
        return Website::query()->findOrFail($id);
    }

    /** @return Collection<int, Website> */
    public function allWebsites(): Collection
    {
        return Website::query()->orderByDesc('is_default')->orderBy('name')->get();
    }

    /** @return Collection<int, Content> */
    public function livePosts(?Website $website, int $limit = 3): Collection
    {
        if ($website === null) {
            return new Collection;
        }

        // Önbellek: yayın/geçersizleme sürümü artırır (bkz. ContentCache).
        return $this->rememberModels($website, "posts:{$limit}", fn () => Content::query()
            ->where('website_id', $website->id)
            ->where('kind', ContentKind::POST->value)
            ->live()
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get());
    }

    /**
     * Site menüsü (faz 10): yayındaki, menüde gösterilen sayfalar; nav_order
     * sonra başlık. Önbellekli (livePages ile aynı sürüm sayacı).
     *
     * @return Collection<int, Content>
     */
    public function navigation(?Website $website): Collection
    {
        if ($website === null) {
            return new Collection;
        }

        return $this->rememberModels($website, 'nav', fn () => Content::query()
            ->where('website_id', $website->id)
            ->where('kind', ContentKind::PAGE->value)
            ->live()
            ->where('show_in_nav', true)
            ->whereNull('parent_id') // alt sayfalar menüde değil; ebeveyn sayfasında listelenir
            ->orderByRaw('nav_order IS NULL, nav_order')
            ->orderBy('title')
            ->get());
    }

    /**
     * Menü düzenleme listesi: sitenin TÜM sayfaları (taslak dahil), menü sırasıyla.
     *
     * @return Collection<int, Content>
     */
    public function pagesFor(Website $website): Collection
    {
        return Content::query()
            ->where('website_id', $website->id)
            ->where('kind', ContentKind::PAGE->value)
            ->orderByRaw('nav_order IS NULL, nav_order')
            ->orderBy('title')
            ->get();
    }

    /**
     * Menü düzeni: sayfa id => [order, show]. Yalnızca verilen sitenin sayfaları
     * güncellenir (yabancı id sessizce atlanır — tenant sınırı çağıranda çizildi,
     * burada ikinci savunma). Menü yapısal alandır: içerik akışından ve
     * revizyondan bağımsız; durumu değiştirmez.
     *
     * @param  array<int, array{order: int|null, show: bool}>  $layout
     */
    public function updateNavigation(Website $website, array $layout): void
    {
        $pages = Content::query()
            ->where('website_id', $website->id)
            ->where('kind', ContentKind::PAGE->value)
            ->whereIn('id', array_keys($layout))
            ->get();

        DB::transaction(function () use ($pages, $layout) {
            foreach ($pages as $page) {
                $row = $layout[$page->id];
                $page->forceFill(['nav_order' => $row['order'], 'show_in_nav' => $row['show']])->save();
            }
        });

        $this->cache->invalidate($website);
    }

    /** Önizleme (faz 48): durumdan bağımsız tek içerik (silinmişler hariç). */
    public function findForPreview(int $id, bool $withDraft = false): ?Content
    {
        $content = Content::query()->with(['website', 'author', 'parent', 'cover', 'draft'])->find($id);

        // Çalışma taslağı önizlemesi: taslak alanları bellekte içeriğin üzerine bindirilir, hiçbir şey yazılmaz.
        if ($withDraft && $content?->draft !== null) {
            $content->fill($content->draft->payload());
        }

        return $content;
    }

    /** @return Collection<int, Content> */
    public function livePages(?Website $website): Collection
    {
        if ($website === null) {
            return new Collection;
        }

        return $this->rememberModels($website, 'pages', fn () => Content::query()
            ->where('website_id', $website->id)
            ->where('kind', ContentKind::PAGE->value)
            ->live()
            ->orderBy('title')
            ->get());
    }

    public function findLive(?Website $website, ContentKind $kind, string $slug): ?Content
    {
        if ($website === null) {
            return null;
        }

        // Slug dışarıdan gelir: anahtara ham değil hash'lenmiş girer.
        return $this->rememberModels($website, "content:{$kind->value}:".sha1($slug), fn () => Content::query()
            ->where('website_id', $website->id)
            ->where('kind', $kind->value)
            ->where('slug', $slug)
            ->live()
            ->limit(1)
            ->get())->first();
    }

    /**
     * Önbelleğe NESNE değil ham öznitelik dizisi yazılır; okurken hydrate edilir.
     *
     * Laravel 13 varsayılanı cache'ten hiçbir PHP nesnesini unserialize etmez
     * (config/cache.php serializable_classes=false — APP_KEY sızarsa gadget-chain
     * savunması). Model önbelleklemek bu savunmayı gevşetmeyi gerektirirdi; ham
     * dizi hem güvenli hem sürücüden bağımsızdır (array/file/database/Redis).
     *
     * @param  Closure(): Collection<int, Content>  $query
     * @return Collection<int, Content>
     */
    private function rememberModels(Website $website, string $name, Closure $query): Collection
    {
        $rows = $this->cache->remember($website, $name, fn () => $query()->map(fn (Content $c) => $c->getAttributes())->all());

        // Savunma: önbellekten dizi dışında bir şey gelirse (eski biçim, bozuk
        // kayıt, __PHP_Incomplete_Class) ıskalama say, yeniden hesapla ve üzerine yaz.
        if (! is_array($rows) || ($rows !== [] && ! is_array(reset($rows)))) {
            $rows = $this->cache->refresh($website, $name, fn () => $query()->map(fn (Content $c) => $c->getAttributes())->all());
        }

        /** @var array<int, array<string, mixed>> $rows */
        return Content::hydrate($rows);
    }

    // -----------------------------------------------------------------
    // Yazma (panel)
    // -----------------------------------------------------------------

    /**
     * Panel listesi: tür/durum süzgeci, başlık/slug araması, sayfalama.
     *
     * @return LengthAwarePaginator<int, Content>
     */
    public function listFor(Website $website, ?ContentKind $kind = null, ?ContentStatus $status = null, ?string $search = null, int $perPage = 30): LengthAwarePaginator
    {
        $search = trim((string) $search);

        return Content::query()
            ->with('author')
            ->where('website_id', $website->id)
            ->when($kind, fn ($q) => $q->where('kind', $kind->value))
            ->when($status, fn ($q) => $q->where('status', $status->value))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('title', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%")))
            ->orderByDesc('updated_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Kalıcı olmayan silme (soft delete): yalnız taslak ya da arşivdeki içerik.
     * Yayındaki metin önce yayından kaldırılır — silme, yayın akışını atlatamaz.
     * Alt sayfaları olan sayfa silinemez (çocuklar yetim kalır).
     */
    public function delete(User $actor, Content $content): void
    {
        if (! in_array($content->status, [ContentStatus::DRAFT, ContentStatus::ARCHIVED], true)) {
            throw new DomainException('Yalnızca taslak ya da arşivdeki içerik silinir; önce yayından kaldırın/arşivleyin.');
        }

        if (Content::query()->where('parent_id', $content->id)->exists()) {
            throw new DomainException('Alt sayfaları olan sayfa silinemez; önce alt sayfaları taşıyın.');
        }

        $wasLive = $content->status === ContentStatus::ARCHIVED && $content->published_at !== null;
        $content->draft?->delete();
        $content->delete();
        $this->cache->invalidate($content->website);

        if ($wasLive) {
            event(new ContentPublicationChanged($content, false));
        }
    }

    /**
     * @param  array{kind: string, title: string, slug?: string|null, excerpt?: string|null, body?: string|null, category?: string|null, tags?: string|null, parent_id?: int|string|null, cover_media_id?: int|string|null, requires_approval?: bool, meta_title?: string|null, meta_description?: string|null, noindex?: bool}  $data
     */
    public function create(User $author, Website $website, array $data): Content
    {
        $kind = ContentKind::from($data['kind']);
        $slug = $this->uniqueSlug($website, $kind, $data['slug'] ?? null, $data['title']);

        return DB::transaction(function () use ($author, $website, $kind, $slug, $data) {
            $parent = $kind === ContentKind::PAGE ? $this->resolveParent($website, null, $data['parent_id'] ?? null) : null;
            $cover = $this->coverFor($website, $data['cover_media_id'] ?? null);

            $content = Content::create([
                'website_id' => $website->id,
                'kind' => $kind,
                'slug' => $slug,
                'parent_id' => $parent?->id,
                'parent_slug' => $parent?->slug,
                'cover_media_id' => $cover?->id,
                'cover_url' => $cover?->url(),
                'title' => trim($data['title']),
                'excerpt' => $data['excerpt'] ?? null,
                'body' => $data['body'] ?? null,
                'category' => $data['category'] ?? null,
                'tags' => self::normalizeTags($data['tags'] ?? null),
                'reading_minutes' => $this->readingMinutes($data['body'] ?? null),
                'requires_approval' => (bool) ($data['requires_approval'] ?? false),
                'meta_title' => $data['meta_title'] ?? null,
                'meta_description' => $data['meta_description'] ?? null,
                'noindex' => (bool) ($data['noindex'] ?? false),
                'author_id' => $author->id,
            ]);
            $content->fill($this->studioFields($website, $data));
            $content->seo_score = SeoAnalyzer::analyze($this->analyzerInput($content))['score'];
            $content->save();

            $this->snapshot($content, $author);
            $this->cache->invalidate($website);

            return $content;
        });
    }

    /**
     * CMS stüdyo alanları (faz 48): SEO/GEO/şema girdisi normalize edilir. Form vermediyse (eski müşteri formu)
     * boş dizi döner — mevcut alanlara dokunulmaz.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function studioFields(Website $website, array $data): array
    {
        if (! array_key_exists('focus_keyword', $data) && ! array_key_exists('geo', $data) && ! array_key_exists('schema_types', $data)) {
            return [];
        }

        $robots = trim((string) ($data['robots'] ?? ''));
        $schemaCustom = trim((string) ($data['schema_custom'] ?? ''));

        if ($schemaCustom !== '' && ! is_array(json_decode($schemaCustom, true))) {
            throw new DomainException('Özel JSON-LD geçerli bir JSON nesnesi/dizisi olmalı.');
        }

        $og = $this->coverFor($website, $data['og_media_id'] ?? null);

        return [
            'focus_keyword' => $this->blankToNull($data['focus_keyword'] ?? null),
            'related_keywords' => self::normalizeTags($data['related_keywords'] ?? null),
            'canonical_url' => $this->blankToNull($data['canonical_url'] ?? null),
            'robots' => in_array($robots, ['index, follow', 'noindex, follow', 'index, nofollow', 'noindex, nofollow'], true) ? $robots : null,
            'og_title' => $this->blankToNull($data['og_title'] ?? null),
            'og_description' => $this->blankToNull($data['og_description'] ?? null),
            'og_media_id' => $og?->id,
            'geo' => GeoSuggester::normalize(is_array($data['geo'] ?? null) ? $data['geo'] : []),
            'schema_types' => array_values(array_intersect(self::SCHEMA_TYPES, array_map('strval', (array) ($data['schema_types'] ?? [])))),
            'schema_custom' => $schemaCustom === '' ? null : $schemaCustom,
        ];
    }

    /** @return array<string, mixed> */
    private function analyzerInput(Content|ContentDraft $c): array
    {
        return [
            'title' => $c->title, 'slug' => $c->slug, 'excerpt' => $c->excerpt, 'body' => $c->body, 'meta_title' => $c->meta_title, 'meta_description' => $c->meta_description,
            'focus_keyword' => $c->focus_keyword, 'canonical_url' => $c->canonical_url, 'schema_types' => $c->schema_types, 'og_title' => $c->og_title,
            'cover' => $c instanceof Content ? $c->cover_media_id : null,
        ];
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array{title: string, slug?: string|null, excerpt?: string|null, body?: string|null, category?: string|null, tags?: string|null, parent_id?: int|string|null, cover_media_id?: int|string|null, requires_approval?: bool, meta_title?: string|null, meta_description?: string|null, noindex?: bool}  $data
     */
    public function update(User $editor, Content $content, array $data): Content
    {
        if ($content->status !== ContentStatus::DRAFT) {
            throw new DomainException(
                'Yalnızca taslak düzenlenir. Bu içerik "'.$content->status->label().'" durumunda; önce taslağa alın.'
            );
        }

        return DB::transaction(function () use ($editor, $content, $data) {
            $slugInput = $data['slug'] ?? null;

            $content->fill([
                'title' => trim($data['title']),
                'slug' => $slugInput !== null && $slugInput !== '' && $slugInput !== $content->slug
                    ? $this->uniqueSlug($content->website, $content->kind, $slugInput, $data['title'], $content->id)
                    : $content->slug,
                'excerpt' => $data['excerpt'] ?? null,
                'body' => $data['body'] ?? null,
                'category' => $data['category'] ?? null,
                'tags' => self::normalizeTags($data['tags'] ?? null),
                'reading_minutes' => $this->readingMinutes($data['body'] ?? null),
                // requires_approval yalnızca yükseltilebilir: bir kez "yasal içerik"
                // işaretlenen metin düzenlemeyle onaysız hale getirilemez.
                'requires_approval' => $content->requires_approval || (bool) ($data['requires_approval'] ?? false),
                'meta_title' => $data['meta_title'] ?? null,
                'meta_description' => $data['meta_description'] ?? null,
                'noindex' => (bool) ($data['noindex'] ?? false),
            ] + $this->studioFields($content->website, $data));

            $cover = $this->coverFor($content->website, $data['cover_media_id'] ?? null);
            $content->cover_media_id = $cover?->id;
            $content->cover_url = $cover?->url();
            $content->seo_score = SeoAnalyzer::analyze($this->analyzerInput($content))['score'];

            if ($content->kind === ContentKind::PAGE) {
                $parent = $this->resolveParent($content->website, $content, $data['parent_id'] ?? null);
                $content->parent_id = $parent?->id;
                $content->parent_slug = $parent?->slug;
            }

            $content->save();

            // Ebeveynin slug'ı değiştiyse çocukların denormalize yolu güncellenir.
            Content::query()->where('parent_id', $content->id)->where('parent_slug', '!=', $content->slug)->update(['parent_slug' => $content->slug]);

            $this->snapshot($content, $editor);
            $this->cache->invalidate($content->website);

            return $content;
        });
    }

    /** Kapak görseli: yalnız aynı sitenin medyası; yabancı id DomainException (sessiz düşmez). */
    private function coverFor(Website $website, int|string|null $mediaId): ?Media
    {
        $mediaId = (int) $mediaId;

        if ($mediaId <= 0) {
            return null;
        }

        $media = Media::query()->where('website_id', $website->id)->find($mediaId);

        if ($media === null) {
            throw new DomainException('Kapak görseli bu sitenin medya kütüphanesinde bulunamadı.');
        }

        return $media;
    }

    /**
     * Ebeveyn sayfa (faz 29, tek seviye): aynı site, kind=page, kendisi üst seviye,
     * içeriğin kendisi değil; çocuğu olan sayfa alt sayfa yapılamaz.
     */
    private function resolveParent(Website $website, ?Content $self, int|string|null $parentId): ?Content
    {
        $parentId = (int) $parentId;

        if ($parentId <= 0) {
            return null;
        }

        $parent = Content::query()->where('website_id', $website->id)->where('kind', ContentKind::PAGE->value)->find($parentId);

        if ($parent === null) {
            throw new DomainException('Ebeveyn sayfa bu sitede bulunamadı.');
        }

        if ($parent->parent_id !== null) {
            throw new DomainException('Alt sayfanın altına sayfa açılamaz (tek seviye).');
        }

        if ($self !== null && ($parent->id === $self->id || Content::query()->where('parent_id', $self->id)->exists())) {
            throw new DomainException('Bu sayfa kendi ebeveyni olamaz; alt sayfası olan sayfa alt sayfa yapılamaz.');
        }

        return $parent;
    }

    /**
     * Ebeveyn adayları: sitenin üst seviye sayfaları (kendisi hariç).
     *
     * @return Collection<int, Content>
     */
    public function parentCandidates(Website $website, ?Content $self = null): Collection
    {
        return Content::query()
            ->where('website_id', $website->id)
            ->where('kind', ContentKind::PAGE->value)
            ->whereNull('parent_id')
            ->when($self, fn ($q) => $q->where('id', '!=', $self->id))
            ->orderBy('title')
            ->get();
    }

    /**
     * Yayındaki alt sayfalar (ebeveyn sayfasında liste).
     *
     * @return Collection<int, Content>
     */
    public function liveChildren(Content $parent): Collection
    {
        return $this->livePages($parent->website)->filter(fn (Content $p) => $p->parent_id === $parent->id)->values();
    }

    /**
     * Editörden "Yayınla" (faz 48; yetki route'ta content.publish): taslak akışı atlamaz, İNCELEMEDE adımından geçip
     * yayınlanır — her adım audit'e düşer; onay gerektiren içerik `transition` kuralıyla reddedilir.
     *
     * @throws DomainException
     */
    public function publishNow(User $actor, Content $content): Content
    {
        $content->refresh(); // yeni oluşturulan kayıtta durum DB varsayılanından (DRAFT) gelir

        if ($content->status === ContentStatus::DRAFT) {
            $content = $this->transition($actor, $content, ContentStatus::IN_REVIEW);
        }

        return $this->transition($actor, $content, ContentStatus::PUBLISHED);
    }

    /**
     * Durum geçişi. Yetki route'ta; burada state machine + onay kuralı.
     *
     * @throws DomainException geçiş tanımlı değilse ya da onay kuralı ihlal edilirse
     */
    public function transition(
        User $actor,
        Content $content,
        ContentStatus $target,
        ?string $note = null,
        ?Carbon $scheduledFor = null,
    ): Content {
        $current = $content->status;

        if ($current === $target) {
            return $content;
        }

        if (! $current->canTransitionTo($target)) {
            throw new DomainException("Geçersiz içerik durumu geçişi: {$current->value} -> {$target->value}");
        }

        $goingLive = in_array($target, [ContentStatus::PUBLISHED, ContentStatus::SCHEDULED], true);
        $wasLive = $current === ContentStatus::PUBLISHED;

        if ($goingLive && $content->requires_approval && $current !== ContentStatus::APPROVED) {
            throw new DomainException('Bu içerik onay gerektirir (yasal/vergi/KYC metni): önce onaylanmalı, sonra yayınlanabilir.');
        }

        if ($goingLive && ($content->body === null || trim($content->body) === '')) {
            throw new DomainException('Boş içerik yayınlanamaz.');
        }

        if ($target === ContentStatus::SCHEDULED && ($scheduledFor === null || $scheduledFor->isPast())) {
            throw new DomainException('Zamanlama için gelecekte bir tarih gerekir.');
        }

        return DB::transaction(function () use ($actor, $content, $target, $note, $scheduledFor, $wasLive) {
            $content->status = $target;

            switch ($target) {
                case ContentStatus::IN_REVIEW:
                    $content->review_note = null;
                    break;
                case ContentStatus::DRAFT:
                    $content->review_note = $note;
                    $content->scheduled_for = null;
                    $content->published_at = null;
                    break;
                case ContentStatus::APPROVED:
                    $content->approved_by = $actor->id;
                    $content->reviewed_by = $actor->id;
                    $content->review_note = $note;
                    break;
                case ContentStatus::SCHEDULED:
                    $content->scheduled_for = $scheduledFor;
                    $content->reviewed_by ??= $actor->id;
                    break;
                case ContentStatus::PUBLISHED:
                    $content->published_by = $actor->id;
                    $content->published_at ??= now();
                    $content->scheduled_for = null;
                    $content->reviewed_by ??= $actor->id;
                    break;
                case ContentStatus::ARCHIVED:
                    $content->review_note = $note;
                    break;
            }

            $content->save();
            $this->cache->invalidate($content->website);

            // Yayın olayı (faz 44): yayına girdi ya da yayından düştü — commit sonrası (IndexNow dinler).
            if ($target === ContentStatus::PUBLISHED || $wasLive) {
                DB::afterCommit(fn () => event(new ContentPublicationChanged($content, $target === ContentStatus::PUBLISHED)));
            }

            return $content;
        });
    }

    /**
     * Zamanı gelen SCHEDULED içerikleri yayınlar (content:publish-scheduled).
     * Zamanlayan kişi yayınlayan olarak kaydedilir; komut kendi başına yetki
     * taşımaz, zamanlama anında content.schedule kontrolü yapılmıştı.
     */
    public function publishScheduled(): int
    {
        $due = Content::query()
            ->where('status', ContentStatus::SCHEDULED->value)
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now())
            ->get();

        foreach ($due as $content) {
            DB::transaction(function () use ($content) {
                $content->status = ContentStatus::PUBLISHED;
                $content->published_at = $content->scheduled_for;
                $content->published_by = $content->reviewed_by;
                $content->scheduled_for = null;
                $content->save();
                $this->cache->invalidate($content->website);
                DB::afterCommit(fn () => event(new ContentPublicationChanged($content, true)));
            });
        }

        // Zamanlanmış çalışma taslakları: zamanı gelince canlı içeriğe birleşir.
        // Aktör = zamanlayan (author_id). Birleşemeyen taslak (içerik yayından
        // düşmüş, yazar silinmiş) bekler ve takvimde gecikmiş olarak görünür.
        $dueDrafts = ContentDraft::query()
            ->with(['content', 'author'])
            ->where('status', ContentStatus::SCHEDULED->value)
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now())
            ->get();

        $merged = 0;

        foreach ($dueDrafts as $draft) {
            $actor = $draft->author;

            if ($actor === null) {
                continue;
            }

            try {
                $this->publishDraft($actor, $draft);
                $merged++;
            } catch (DomainException) {
                // taslak bekler
            }
        }

        return $due->count() + $merged;
    }

    // -----------------------------------------------------------------
    // Müşteri sitesi (faz 10): organizasyonun siteleri ve içeriği
    // -----------------------------------------------------------------

    /** @return Collection<int, Content> */
    public function listForOrganization(int $organizationId): Collection
    {
        return Content::query()
            ->with(['author', 'website', 'draft'])
            ->whereHas('website', fn ($q) => $q->where('organization_id', $organizationId))
            ->orderBy('kind')
            ->orderByDesc('updated_at')
            ->get();
    }

    /** Başka organizasyonun içeriği null döner (çağıran 404 verir; 403 varlığı sızdırır). */
    public function findForOrganization(int $organizationId, int $contentId): ?Content
    {
        return Content::query()
            ->with(['website', 'draft'])
            ->whereKey($contentId)
            ->whereHas('website', fn ($q) => $q->where('organization_id', $organizationId))
            ->first();
    }

    // -----------------------------------------------------------------
    // Kategori + ilgili yazılar (faz 18 / 23 iç bağlantı)
    // -----------------------------------------------------------------

    /**
     * Etiket normalizasyonu: virgülle ayrılmış metin ya da dizi -> küçük harf,
     * kırpılmış, tekil, en fazla 10, her biri ≤ 40 karakter. Boş -> null.
     *
     * @param  string|array<int, string>|null  $input
     * @return array<int, string>|null
     */
    public static function normalizeTags(string|array|null $input): ?array
    {
        $raw = is_array($input) ? $input : explode(',', (string) $input);
        $tags = [];

        foreach ($raw as $tag) {
            $tag = mb_substr(trim(mb_strtolower((string) $tag)), 0, 40);

            if ($tag !== '' && ! in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }

            if (count($tags) === 10) {
                break;
            }
        }

        return $tags === [] ? null : $tags;
    }

    /**
     * Yayındaki yazıların etiketleri: slug => [name, count]. Önbellekli.
     *
     * @return array<string, array{name: string, count: int}>
     */
    public function tags(?Website $website): array
    {
        if ($website === null) {
            return [];
        }

        $rows = $this->cache->remember($website, 'tags', function () use ($website) {
            $out = [];

            foreach ($this->livePosts($website, 1000) as $post) {
                foreach ($post->tags ?? [] as $tag) {
                    $slug = Str::slug($tag);

                    if ($slug === '') {
                        continue;
                    }

                    $out[$slug] ??= ['name' => $tag, 'count' => 0];
                    $out[$slug]['count']++;
                }
            }

            ksort($out);

            return $out;
        });

        return is_array($rows) ? $rows : [];
    }

    /** @return Collection<int, Content> */
    public function livePostsWithTag(?Website $website, string $tagSlug): Collection
    {
        if ($website === null) {
            return new Collection;
        }

        return $this->livePosts($website, 1000)
            ->filter(fn (Content $post) => in_array($tagSlug, array_map(fn ($t) => Str::slug((string) $t), $post->tags ?? []), true))
            ->values();
    }

    /**
     * İç bağlantı önerisi (faz 18/23): aynı sitedeki yayındaki içerikler,
     * puan = ortak etiket ×3 + aynı kategori ×2 + başlık kelime kesişimi ×1.
     * Editör panelde görür; kopyalanacak markdown bağlantısı hazırdır.
     *
     * @return array<int, array{content: Content, score: int, reasons: array<int, string>}>
     */
    public function linkSuggestions(Content $content, int $limit = 5): array
    {
        $website = $content->website;
        $pool = $this->livePosts($website, 1000)->concat($this->livePages($website))
            ->reject(fn (Content $c) => $c->id === $content->id);

        $myTags = array_map('mb_strtolower', $content->tags ?? []);
        $myWords = self::titleWords($content->title);
        $out = [];

        foreach ($pool as $candidate) {
            $score = 0;
            $reasons = [];

            $shared = array_intersect($myTags, array_map('mb_strtolower', $candidate->tags ?? []));

            if ($shared !== []) {
                $score += 3 * count($shared);
                $reasons[] = 'ortak etiket: '.implode(', ', $shared);
            }

            if ($content->category !== null && $content->category === $candidate->category) {
                $score += 2;
                $reasons[] = 'aynı kategori';
            }

            $words = array_intersect($myWords, self::titleWords($candidate->title));

            if ($words !== []) {
                $score += count($words);
                $reasons[] = 'başlıkta: '.implode(', ', $words);
            }

            if ($score > 0) {
                $out[] = ['content' => $candidate, 'score' => $score, 'reasons' => $reasons];
            }
        }

        usort($out, fn (array $a, array $b) => $b['score'] <=> $a['score'] ?: strcmp($a['content']->title, $b['content']->title));

        return array_slice($out, 0, $limit);
    }

    /**
     * Başlığın anlamlı kelimeleri (≥ 4 harf, sık bağlaçlar hariç).
     *
     * @return array<int, string>
     */
    private static function titleWords(string $title): array
    {
        $stop = ['için', 'ile', 'veya', 'nasıl', 'nedir', 'olan', 'olarak', 'daha', 'kadar', 'gibi', 'sonra', 'önce', 'hakkında'];
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($title)) ?: [];

        return array_values(array_unique(array_filter($words, fn (string $w) => mb_strlen($w) >= 4 && ! in_array($w, $stop, true))));
    }

    /**
     * Yayındaki yazıların kategorileri: slug => [name, count]. Önbellekli.
     *
     * @return array<string, array{name: string, count: int}>
     */
    public function categories(?Website $website): array
    {
        if ($website === null) {
            return [];
        }

        $rows = $this->cache->remember($website, 'categories', function () use ($website) {
            $out = [];

            $names = Content::query()
                ->where('website_id', $website->id)
                ->where('kind', ContentKind::POST->value)
                ->live()
                ->whereNotNull('category')
                ->pluck('category');

            foreach ($names as $name) {
                $slug = Str::slug((string) $name);

                if ($slug === '') {
                    continue;
                }

                $out[$slug] ??= ['name' => (string) $name, 'count' => 0];
                $out[$slug]['count']++;
            }

            ksort($out);

            return $out;
        });

        return is_array($rows) ? $rows : [];
    }

    /**
     * Kategori sayfası: kategori serbest metindir, eşleme slug üzerinden yapılır.
     *
     * @return Collection<int, Content>
     */
    public function livePostsInCategory(?Website $website, string $categorySlug): Collection
    {
        if ($website === null) {
            return new Collection;
        }

        return $this->livePosts($website, 1000)
            ->filter(fn (Content $post) => Str::slug((string) $post->category) === $categorySlug)
            ->values();
    }

    /**
     * İlgili yazılar (faz 23 iç bağlantı): önce aynı kategori, sonra en yeni.
     *
     * @return Collection<int, Content>
     */
    public function relatedPosts(Content $content, int $limit = 3): Collection
    {
        $others = $this->livePosts($content->website, 1000)->reject(fn (Content $p) => $p->id === $content->id);
        $same = $others->filter(fn (Content $p) => $p->category !== null && $p->category === $content->category);
        $rest = $others->reject(fn (Content $p) => $same->contains('id', $p->id));

        return $same->concat($rest)->take($limit)->values();
    }

    // -----------------------------------------------------------------
    // Çalışma taslağı (faz 18): canlı metni düşürmeden düzenleme
    // -----------------------------------------------------------------

    /**
     * Yayındaki içerik için çalışma taslağı açar (zaten varsa onu döner).
     * Taslak içerik doğrudan update() ile düzenlenir; burada yalnız PUBLISHED.
     */
    public function openDraft(User $author, Content $content): ContentDraft
    {
        if ($content->status !== ContentStatus::PUBLISHED) {
            throw new DomainException('Çalışma taslağı yalnızca yayındaki içerik için açılır; taslak içerik doğrudan düzenlenir.');
        }

        $existing = $content->draft;

        if ($existing !== null) {
            return $existing;
        }

        return ContentDraft::create([
            'content_id' => $content->id,
            'title' => $content->title,
            'slug' => $content->slug,
            'excerpt' => $content->excerpt,
            'body' => $content->body,
            'category' => $content->category,
            'tags' => $content->tags,
            'meta_title' => $content->meta_title,
            'meta_description' => $content->meta_description,
            'noindex' => $content->noindex,
            'author_id' => $author->id,
        ] + $content->only(Content::STUDIO_FIELDS));
    }

    /**
     * @param  array{title: string, slug?: string|null, excerpt?: string|null, body?: string|null, category?: string|null, tags?: string|null, meta_title?: string|null, meta_description?: string|null, noindex?: bool}  $data
     */
    public function updateDraft(User $editor, ContentDraft $draft, array $data): ContentDraft
    {
        if ($draft->status !== ContentStatus::DRAFT) {
            throw new DomainException('Taslak "'.$draft->status->label().'" durumunda; düzenlemek için önce geri gönderilmeli.');
        }

        $content = $draft->content;
        $slugInput = $data['slug'] ?? null;

        $draft->fill([
            'title' => trim($data['title']),
            'slug' => $slugInput !== null && $slugInput !== '' && $slugInput !== $draft->slug
                ? $this->uniqueSlug($content->website, $content->kind, $slugInput, $data['title'], $content->id)
                : $draft->slug,
            'excerpt' => $data['excerpt'] ?? null,
            'body' => $data['body'] ?? null,
            'category' => $data['category'] ?? null,
            'tags' => self::normalizeTags($data['tags'] ?? null),
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'noindex' => (bool) ($data['noindex'] ?? false),
            'author_id' => $editor->id,
        ] + $this->studioFields($content->website, $data));
        $draft->save();

        return $draft;
    }

    /**
     * Taslak akışı: DRAFT -> IN_REVIEW -> DRAFT (geri) | APPROVED. Yayın = publishDraft().
     */
    public function transitionDraft(User $actor, ContentDraft $draft, ContentStatus $target, ?string $note = null, ?Carbon $scheduledFor = null): ContentDraft
    {
        if (! in_array($target, [ContentStatus::DRAFT, ContentStatus::IN_REVIEW, ContentStatus::APPROVED, ContentStatus::SCHEDULED], true)) {
            throw new DomainException('Çalışma taslağı yalnızca taslak/inceleme/onay/zamanlanmış durumlarını alır; yayın birleştirme ile yapılır.');
        }

        if (! $draft->status->canTransitionTo($target)) {
            throw new DomainException("Geçersiz taslak geçişi: {$draft->status->value} -> {$target->value}");
        }

        if ($target === ContentStatus::SCHEDULED) {
            $this->assertDraftMergeable($draft);

            if ($scheduledFor === null || $scheduledFor->isPast()) {
                throw new DomainException('Zamanlama için gelecekte bir tarih gerekir.');
            }
        }

        $draft->status = $target;
        $draft->review_note = $target === ContentStatus::IN_REVIEW ? null : $note;
        $draft->approved_by = $target === ContentStatus::APPROVED ? $actor->id : ($target === ContentStatus::SCHEDULED ? $draft->approved_by : null);
        $draft->scheduled_for = $target === ContentStatus::SCHEDULED ? $scheduledFor : null;
        $draft->save();

        return $draft;
    }

    /**
     * Birleştirme ön koşulları (yayınla ve zamanla için ortak). Onay gerektiren
     * içerikte taslak APPROVED'dan geçmiş olmalı (approved_by dolu); zamanlanmış
     * taslak onayını korur.
     */
    private function assertDraftMergeable(ContentDraft $draft): void
    {
        $content = $draft->content;

        if (! in_array($draft->status, [ContentStatus::IN_REVIEW, ContentStatus::APPROVED, ContentStatus::SCHEDULED], true)) {
            throw new DomainException('Taslak incelemeye gönderilmeden yayınlanamaz.');
        }

        if ($content->requires_approval && $draft->approved_by === null) {
            throw new DomainException('Bu içerik onay gerektirir: taslak önce onaylanmalı.');
        }

        if ($draft->body === null || trim($draft->body) === '') {
            throw new DomainException('Boş içerik yayınlanamaz.');
        }

        if ($content->status !== ContentStatus::PUBLISHED) {
            throw new DomainException('İçerik artık yayında değil; taslağı silip içeriği doğrudan düzenleyin.');
        }
    }

    /**
     * Taslağı canlı içeriğe birleştirir: içerik yayında KALIR, alanları güncellenir,
     * revizyon düşer, önbellek geçersiz kılınır, taslak silinir.
     */
    public function publishDraft(User $actor, ContentDraft $draft): Content
    {
        $content = $draft->content;
        $this->assertDraftMergeable($draft);

        return DB::transaction(function () use ($actor, $draft, $content) {
            $payload = $draft->payload();
            // Taslak beklerken slug başka içerikçe alınmış olabilir; tekillik burada yeniden çözülür.
            $payload['slug'] = $this->uniqueSlug($content->website, $content->kind, $payload['slug'], $payload['title'], $content->id);
            $content->fill($payload);
            $content->reading_minutes = $this->readingMinutes($content->body);
            $content->seo_score = SeoAnalyzer::analyze($this->analyzerInput($content))['score'];
            $content->save();

            $this->snapshot($content, $actor);
            $draft->delete();
            $this->cache->invalidate($content->website);

            return $content;
        });
    }

    public function discardDraft(ContentDraft $draft): void
    {
        $draft->delete();
    }

    // -----------------------------------------------------------------
    // İçerik takvimi (faz 24)
    // -----------------------------------------------------------------

    /**
     * Aylık takvim: gün (Y-m-d) => içerikler. Zamanlanmış içerik scheduled_for,
     * yayındaki içerik published_at gününe düşer; başka durumlar takvimde yoktur.
     *
     * Zamanlanmış çalışma taslakları da (ContentDraft) birleşme gününe düşer.
     *
     * @return array<string, Collection<int, Content|ContentDraft>>
     */
    public function calendar(Website $website, CarbonImmutable $month): array
    {
        return $this->calendarRange($website, $month->startOfMonth(), $month->endOfMonth());
    }

    /**
     * Tarih aralığı için takvim (haftalık görünüm de bunu kullanır).
     *
     * @return array<string, Collection<int, Content|ContentDraft>>
     */
    public function calendarRange(Website $website, CarbonImmutable $start, CarbonImmutable $end): array
    {

        $items = Content::query()
            ->with('author')
            ->where('website_id', $website->id)
            ->where(function ($q) use ($start, $end) {
                $q->where(fn ($s) => $s->where('status', ContentStatus::SCHEDULED->value)->whereBetween('scheduled_for', [$start, $end]))
                    ->orWhere(fn ($p) => $p->where('status', ContentStatus::PUBLISHED->value)->whereBetween('published_at', [$start, $end]));
            })
            ->orderBy('scheduled_for')
            ->orderBy('published_at')
            ->get();

        $days = [];

        foreach ($items as $item) {
            $at = $item->status === ContentStatus::SCHEDULED ? $item->scheduled_for : $item->published_at;
            $key = $at?->format('Y-m-d');

            if ($key === null) {
                continue;
            }

            $days[$key] ??= new Collection;
            $days[$key]->push($item);
        }

        $drafts = ContentDraft::query()
            ->with(['content', 'author'])
            ->where('status', ContentStatus::SCHEDULED->value)
            ->whereBetween('scheduled_for', [$start, $end])
            ->whereHas('content', fn ($q) => $q->where('website_id', $website->id))
            ->orderBy('scheduled_for')
            ->get();

        foreach ($drafts as $draft) {
            $key = $draft->scheduled_for?->format('Y-m-d');

            if ($key === null) {
                continue;
            }

            $days[$key] ??= new Collection;
            $days[$key]->push($draft);
        }

        ksort($days);

        return $days;
    }

    /**
     * İş hattı: yayın öncesi bekleyenler + çalışma taslakları + gecikmiş zamanlama.
     *
     * Gecikmiş = SCHEDULED ama zamanı geçmiş: zamanlayıcı (content:publish-scheduled)
     * çalışmıyor demektir; takvimde uyarı olarak gösterilir.
     *
     * @return array{draft: Collection<int, Content>, in_review: Collection<int, Content>, approved: Collection<int, Content>, working: Collection<int, ContentDraft>, overdue: Collection<int, Content|ContentDraft>}
     */
    /**
     * Editör iş yükü (faz 24): yazar başına yayın öncesi içerik + çalışma taslağı sayıları.
     *
     * @return array<int, array{name: string, draft: int, in_review: int, approved: int, scheduled: int, working: int, total: int}>
     */
    public function workload(Website $website): array
    {
        $pipeline = $this->pipeline($website);

        // [yazar id => [kova => içerik listesi]] — pipeline SCHEDULED'ı 'overdue' dışında tutmaz, ayrıca sorulur.
        $items = [];

        foreach (['draft', 'in_review', 'approved'] as $bucket) {
            foreach ($pipeline[$bucket] as $content) {
                $items[] = [$content->author, $bucket];
            }
        }

        foreach ($pipeline['working'] as $draft) {
            $items[] = [$draft->author, 'working'];
        }

        $scheduled = Content::query()->with('author')->where('website_id', $website->id)->where('status', ContentStatus::SCHEDULED->value)->get();

        foreach ($scheduled as $content) {
            $items[] = [$content->author, 'scheduled'];
        }

        /** @var array<int, array{name: string, draft: int, in_review: int, approved: int, scheduled: int, working: int, total: int}> $rows */
        $rows = [];

        foreach ($items as [$author, $bucket]) {
            $key = $author instanceof User ? $author->id : 0;

            if (! isset($rows[$key])) {
                $rows[$key] = ['name' => $author instanceof User ? $author->name : '— (yazar yok)', 'draft' => 0, 'in_review' => 0, 'approved' => 0, 'scheduled' => 0, 'working' => 0, 'total' => 0];
            }

            $rows[$key][$bucket]++;
            $rows[$key]['total']++;
        }

        $list = array_values($rows);
        usort($list, fn (array $a, array $b) => $b['total'] <=> $a['total'] ?: strcmp($a['name'], $b['name']));

        return $list;
    }

    public function pipeline(Website $website): array
    {
        $pending = Content::query()
            ->with('author')
            ->where('website_id', $website->id)
            ->whereIn('status', [ContentStatus::DRAFT->value, ContentStatus::IN_REVIEW->value, ContentStatus::APPROVED->value, ContentStatus::SCHEDULED->value])
            ->orderByDesc('updated_at')
            ->get();

        $working = ContentDraft::query()
            ->with(['content', 'author'])
            ->whereHas('content', fn ($q) => $q->where('website_id', $website->id))
            ->orderByDesc('updated_at')
            ->get();

        return [
            'draft' => $pending->where('status', ContentStatus::DRAFT)->values(),
            'in_review' => $pending->where('status', ContentStatus::IN_REVIEW)->values(),
            'approved' => $pending->where('status', ContentStatus::APPROVED)->values(),
            'working' => $working,
            'overdue' => $pending
                ->filter(fn (Content $c) => $c->status === ContentStatus::SCHEDULED && $c->scheduled_for !== null && $c->scheduled_for->isPast())
                ->concat($working->filter(fn (ContentDraft $d) => $d->status === ContentStatus::SCHEDULED && $d->scheduled_for !== null && $d->scheduled_for->isPast()))
                ->values(),
        ];
    }

    /**
     * Sayfa yolu çözümü (faz 29): /slug yalnız üst seviye, /ebeveyn/slug yalnız o ebeveynin
     * çocuğu — kanonik dışı yol 404 (aynı sayfa iki adreste yaşamaz).
     */
    public function findLivePage(?Website $website, string $slug, ?string $parentSlug = null): ?Content
    {
        $page = $this->findLive($website, ContentKind::PAGE, $slug);

        if ($page === null || ($page->parent_slug ?? null) !== $parentSlug) {
            return null;
        }

        return $page;
    }

    /** @return Collection<int, ContentRevision> */
    public function revisionsOf(Content $content): Collection
    {
        return $content->revisions()->with('editor')->orderByDesc('number')->get();
    }

    // -----------------------------------------------------------------

    private function snapshot(Content $content, User $editor): void
    {
        $number = (int) ContentRevision::query()->where('content_id', $content->id)->max('number') + 1;

        ContentRevision::create([
            'content_id' => $content->id,
            'number' => $number,
            'title' => $content->title,
            'excerpt' => $content->excerpt,
            'body' => $content->body,
            'edited_by' => $editor->id,
            'created_at' => now(),
        ]);
    }

    private function uniqueSlug(Website $website, ContentKind $kind, ?string $requested, string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($requested ?: $title);

        if ($base === '') {
            throw new DomainException('Başlıktan geçerli bir slug üretilemedi.');
        }

        $slug = $base;
        $i = 2;

        while (Content::withTrashed()
            ->where('website_id', $website->id)
            ->where('kind', $kind->value)
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    private function readingMinutes(?string $body): ?int
    {
        if ($body === null || trim($body) === '') {
            return null;
        }

        return max(1, (int) ceil(str_word_count(strip_tags($body)) / self::WORDS_PER_MINUTE));
    }
}
