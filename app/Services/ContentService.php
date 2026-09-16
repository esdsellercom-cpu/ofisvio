<?php

namespace App\Services;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\ContentDraft;
use App\Models\ContentRevision;
use App\Models\User;
use App\Models\Website;
use Carbon\CarbonImmutable;
use Closure;
use DomainException;
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

    /** @return Collection<int, Content> */
    public function listFor(Website $website, ?ContentKind $kind = null, ?ContentStatus $status = null): Collection
    {
        return Content::query()
            ->with('author')
            ->where('website_id', $website->id)
            ->when($kind, fn ($q) => $q->where('kind', $kind->value))
            ->when($status, fn ($q) => $q->where('status', $status->value))
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * @param  array{kind: string, title: string, slug?: string|null, excerpt?: string|null, body?: string|null, category?: string|null, requires_approval?: bool, meta_title?: string|null, meta_description?: string|null, noindex?: bool}  $data
     */
    public function create(User $author, Website $website, array $data): Content
    {
        $kind = ContentKind::from($data['kind']);
        $slug = $this->uniqueSlug($website, $kind, $data['slug'] ?? null, $data['title']);

        return DB::transaction(function () use ($author, $website, $kind, $slug, $data) {
            $content = Content::create([
                'website_id' => $website->id,
                'kind' => $kind,
                'slug' => $slug,
                'title' => trim($data['title']),
                'excerpt' => $data['excerpt'] ?? null,
                'body' => $data['body'] ?? null,
                'category' => $data['category'] ?? null,
                'reading_minutes' => $this->readingMinutes($data['body'] ?? null),
                'requires_approval' => (bool) ($data['requires_approval'] ?? false),
                'meta_title' => $data['meta_title'] ?? null,
                'meta_description' => $data['meta_description'] ?? null,
                'noindex' => (bool) ($data['noindex'] ?? false),
                'author_id' => $author->id,
            ]);

            $this->snapshot($content, $author);
            $this->cache->invalidate($website);

            return $content;
        });
    }

    /**
     * @param  array{title: string, slug?: string|null, excerpt?: string|null, body?: string|null, category?: string|null, requires_approval?: bool, meta_title?: string|null, meta_description?: string|null, noindex?: bool}  $data
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
                'reading_minutes' => $this->readingMinutes($data['body'] ?? null),
                // requires_approval yalnızca yükseltilebilir: bir kez "yasal içerik"
                // işaretlenen metin düzenlemeyle onaysız hale getirilemez.
                'requires_approval' => $content->requires_approval || (bool) ($data['requires_approval'] ?? false),
                'meta_title' => $data['meta_title'] ?? null,
                'meta_description' => $data['meta_description'] ?? null,
                'noindex' => (bool) ($data['noindex'] ?? false),
            ]);
            $content->save();

            $this->snapshot($content, $editor);
            $this->cache->invalidate($content->website);

            return $content;
        });
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

        if ($goingLive && $content->requires_approval && $current !== ContentStatus::APPROVED) {
            throw new DomainException('Bu içerik onay gerektirir (yasal/vergi/KYC metni): önce onaylanmalı, sonra yayınlanabilir.');
        }

        if ($goingLive && ($content->body === null || trim($content->body) === '')) {
            throw new DomainException('Boş içerik yayınlanamaz.');
        }

        if ($target === ContentStatus::SCHEDULED && ($scheduledFor === null || $scheduledFor->isPast())) {
            throw new DomainException('Zamanlama için gelecekte bir tarih gerekir.');
        }

        return DB::transaction(function () use ($actor, $content, $target, $note, $scheduledFor) {
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
            });
        }

        return $due->count();
    }

    // -----------------------------------------------------------------
    // Kategori + ilgili yazılar (faz 18 / 23 iç bağlantı)
    // -----------------------------------------------------------------

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
            'meta_title' => $content->meta_title,
            'meta_description' => $content->meta_description,
            'noindex' => $content->noindex,
            'author_id' => $author->id,
        ]);
    }

    /**
     * @param  array{title: string, slug?: string|null, excerpt?: string|null, body?: string|null, category?: string|null, meta_title?: string|null, meta_description?: string|null, noindex?: bool}  $data
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
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'noindex' => (bool) ($data['noindex'] ?? false),
            'author_id' => $editor->id,
        ]);
        $draft->save();

        return $draft;
    }

    /**
     * Taslak akışı: DRAFT -> IN_REVIEW -> DRAFT (geri) | APPROVED. Yayın = publishDraft().
     */
    public function transitionDraft(User $actor, ContentDraft $draft, ContentStatus $target, ?string $note = null): ContentDraft
    {
        if (! in_array($target, [ContentStatus::DRAFT, ContentStatus::IN_REVIEW, ContentStatus::APPROVED], true)) {
            throw new DomainException('Çalışma taslağı yalnızca taslak/inceleme/onay durumlarını alır; yayın birleştirme ile yapılır.');
        }

        if (! $draft->status->canTransitionTo($target)) {
            throw new DomainException("Geçersiz taslak geçişi: {$draft->status->value} -> {$target->value}");
        }

        $draft->status = $target;
        $draft->review_note = $target === ContentStatus::IN_REVIEW ? null : $note;
        $draft->approved_by = $target === ContentStatus::APPROVED ? $actor->id : null;
        $draft->save();

        return $draft;
    }

    /**
     * Taslağı canlı içeriğe birleştirir: içerik yayında KALIR, alanları güncellenir,
     * revizyon düşer, önbellek geçersiz kılınır, taslak silinir.
     */
    public function publishDraft(User $actor, ContentDraft $draft): Content
    {
        $content = $draft->content;

        if (! in_array($draft->status, [ContentStatus::IN_REVIEW, ContentStatus::APPROVED], true)) {
            throw new DomainException('Taslak incelemeye gönderilmeden yayınlanamaz.');
        }

        if ($content->requires_approval && $draft->status !== ContentStatus::APPROVED) {
            throw new DomainException('Bu içerik onay gerektirir: taslak önce onaylanmalı.');
        }

        if ($draft->body === null || trim($draft->body) === '') {
            throw new DomainException('Boş içerik yayınlanamaz.');
        }

        if ($content->status !== ContentStatus::PUBLISHED) {
            throw new DomainException('İçerik artık yayında değil; taslağı silip içeriği doğrudan düzenleyin.');
        }

        return DB::transaction(function () use ($actor, $draft, $content) {
            $payload = $draft->payload();
            // Taslak beklerken slug başka içerikçe alınmış olabilir; tekillik burada yeniden çözülür.
            $payload['slug'] = $this->uniqueSlug($content->website, $content->kind, $payload['slug'], $payload['title'], $content->id);
            $content->fill($payload);
            $content->reading_minutes = $this->readingMinutes($content->body);
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
     * @return array<string, Collection<int, Content>>
     */
    public function calendar(Website $website, CarbonImmutable $month): array
    {
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();

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

        ksort($days);

        return $days;
    }

    /**
     * İş hattı: yayın öncesi bekleyenler + çalışma taslakları + gecikmiş zamanlama.
     *
     * Gecikmiş = SCHEDULED ama zamanı geçmiş: zamanlayıcı (content:publish-scheduled)
     * çalışmıyor demektir; takvimde uyarı olarak gösterilir.
     *
     * @return array{draft: Collection<int, Content>, in_review: Collection<int, Content>, approved: Collection<int, Content>, working: Collection<int, ContentDraft>, overdue: Collection<int, Content>}
     */
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
            'overdue' => $pending->filter(fn (Content $c) => $c->status === ContentStatus::SCHEDULED && $c->scheduled_for !== null && $c->scheduled_for->isPast())->values(),
        ];
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
