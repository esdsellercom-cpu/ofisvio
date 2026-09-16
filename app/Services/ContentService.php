<?php

namespace App\Services;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\User;
use App\Models\Website;
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

    /** @return Collection<int, Content> */
    public function livePosts(?Website $website, int $limit = 3): Collection
    {
        if ($website === null) {
            return new Collection;
        }

        return Content::query()
            ->where('website_id', $website->id)
            ->where('kind', ContentKind::POST->value)
            ->live()
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
    }

    /** @return Collection<int, Content> */
    public function livePages(?Website $website): Collection
    {
        if ($website === null) {
            return new Collection;
        }

        return Content::query()
            ->where('website_id', $website->id)
            ->where('kind', ContentKind::PAGE->value)
            ->live()
            ->orderBy('title')
            ->get();
    }

    public function findLive(?Website $website, ContentKind $kind, string $slug): ?Content
    {
        if ($website === null) {
            return null;
        }

        return Content::query()
            ->where('website_id', $website->id)
            ->where('kind', $kind->value)
            ->where('slug', $slug)
            ->live()
            ->first();
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
     * @param  array{kind: string, title: string, slug?: string|null, excerpt?: string|null, body?: string|null, category?: string|null, requires_approval?: bool, meta_title?: string|null, meta_description?: string|null}  $data
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
                'author_id' => $author->id,
            ]);

            $this->snapshot($content, $author);

            return $content;
        });
    }

    /**
     * @param  array{title: string, slug?: string|null, excerpt?: string|null, body?: string|null, category?: string|null, requires_approval?: bool, meta_title?: string|null, meta_description?: string|null}  $data
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
            ]);
            $content->save();

            $this->snapshot($content, $editor);

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
            });
        }

        return $due->count();
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
