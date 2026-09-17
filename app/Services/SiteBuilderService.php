<?php

namespace App\Services;

use App\Models\SiteRevision;
use App\Models\SiteSection;
use App\Models\User;
use App\Models\Website;
use App\Site\SectionLibrary;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Ana sayfa kurucu (master prompt §21–36): bölüm ekle/taşı/çoğalt/gizle/sil/zamanla
 * (taslak, content.edit) → yayınla (anlık görüntü = revizyon, content.publish) → vitrin
 * yayınlanmış revizyonu basar (ContentCache ile sürümlü). Geri alma eski revizyonu
 * taslağa kopyalar ve yeni revizyon olarak yayınlar (denetim izli). Önizleme: imzalı,
 * süreli URL; taslak yalnız orada görünür (noindex, önbellek yok).
 *
 * Yayınlanmış revizyon yoksa vitrin varsayılan yerleşimi basar (SectionLibrary::defaultLayout)
 * — eski davranış korunur, kurulum kırılmaz.
 */
class SiteBuilderService
{
    public const PREVIEW_MINUTES = 30;

    public function __construct(
        private readonly ContentCache $cache,
        private readonly AuditService $audit,
    ) {}

    // ---- Taslak --------------------------------------------------------------

    /** @return Collection<int, SiteSection> */
    public function draft(Website $website): Collection
    {
        $this->ensureDraft($website);

        return SiteSection::query()->where('website_id', $website->id)->orderBy('sort_order')->orderBy('id')->get();
    }

    /** Taslak boşsa varsayılan yerleşimi yazar (ilk açılış). */
    private function ensureDraft(Website $website): void
    {
        if (SiteSection::query()->where('website_id', $website->id)->exists()) {
            return;
        }

        foreach (SectionLibrary::defaultLayout() as $i => $row) {
            SiteSection::query()->create(['website_id' => $website->id, 'type' => $row['type'], 'anchor' => $row['anchor'], 'sort_order' => $i + 1, 'is_visible' => true, 'settings' => []]);
        }
    }

    public function find(Website $website, int $id): SiteSection
    {
        $section = SiteSection::query()->where('website_id', $website->id)->find($id);

        if ($section === null) {
            throw new DomainException('Bölüm bulunamadı.');
        }

        return $section;
    }

    public function add(User $actor, Website $website, string $type, ?int $afterId = null): SiteSection
    {
        $def = SectionLibrary::type($type);
        $draft = $this->draft($website);

        if (($def['unique'] ?? false) && $draft->contains(fn (SiteSection $s) => $s->type === $type)) {
            throw new DomainException($def['label'].' bölümü sayfada zaten var (tek olabilir).');
        }

        if ($draft->count() >= 24) {
            throw new DomainException('En fazla 24 bölüm.');
        }

        $position = $afterId !== null ? ($draft->firstWhere('id', $afterId)->sort_order ?? $draft->count()) + 1 : $draft->count() + 1;
        $this->shift($website, $position);

        $section = SiteSection::query()->create(['website_id' => $website->id, 'type' => $type, 'anchor' => $this->uniqueAnchor($website, $type), 'sort_order' => $position, 'is_visible' => true, 'settings' => [], 'updated_by' => $actor->id]);
        $this->audit->record($actor, 'site.section_added', 'site_section', $section->id, [], ['type' => $type, 'website_id' => $website->id]);

        return $section;
    }

    /**
     * @param  array<string, mixed>  $input  form alanları: settings.*, anchor, is_visible, hide_on_mobile, hide_on_desktop, publish_from, publish_until
     */
    public function update(User $actor, Website $website, SiteSection $section, array $input): SiteSection
    {
        $def = SectionLibrary::type($section->type);
        $settings = [];

        foreach ($def['fields'] as $key => $field) {
            $value = $input['settings'][$key] ?? null;

            $settings[$key] = match ($field['type']) {
                'cta' => $this->normalizeCta(is_array($value) ? $value : []),
                'lines' => array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $value) ?: []))),
                'select' => isset($field['options'][(string) $value]) ? (string) $value : (string) array_key_first($field['options'] ?? ['' => '']),
                default => trim((string) $value),
            };
        }

        if ($section->type === 'blog') {
            $settings['limit'] = (string) max(1, min(6, (int) ($settings['limit'] ?: 3)));
        }

        $anchor = trim((string) ($input['anchor'] ?? ''));

        if ($anchor !== '' && preg_match('/^[a-z0-9-]{2,40}$/', $anchor) !== 1) {
            throw new DomainException('Çapa yalnız küçük harf, rakam ve tire içerebilir.');
        }

        $from = $this->dateOrNull($input['publish_from'] ?? null);
        $until = $this->dateOrNull($input['publish_until'] ?? null);

        if ($from !== null && $until !== null && $until->lessThanOrEqualTo($from)) {
            throw new DomainException('Bitiş, başlangıçtan sonra olmalı.');
        }

        $before = $section->toSnapshot();
        $section->fill([
            'anchor' => $anchor === '' ? null : $anchor,
            'is_visible' => (bool) ($input['is_visible'] ?? false),
            'hide_on_mobile' => (bool) ($input['hide_on_mobile'] ?? false),
            'hide_on_desktop' => (bool) ($input['hide_on_desktop'] ?? false),
            'settings' => array_filter($settings, fn ($v) => $v !== '' && $v !== []),
            'publish_from' => $from,
            'publish_until' => $until,
            'updated_by' => $actor->id,
        ])->save();
        $this->audit->record($actor, 'site.section_updated', 'site_section', $section->id, $before, $section->toSnapshot());

        return $section;
    }

    public function move(User $actor, Website $website, SiteSection $section, string $direction): void
    {
        $draft = $this->draft($website)->values();
        $index = $draft->search(fn (SiteSection $s) => $s->id === $section->id);
        $target = $direction === 'up' ? $index - 1 : $index + 1;

        if ($index === false || $target < 0 || $target >= $draft->count()) {
            return;
        }

        $other = $draft[$target];
        [$a, $b] = [$section->sort_order, $other->sort_order];
        $section->forceFill(['sort_order' => $b, 'updated_by' => $actor->id])->save();
        $other->forceFill(['sort_order' => $a])->save();
        $this->audit->record($actor, 'site.section_moved', 'site_section', $section->id, ['sort_order' => $a], ['sort_order' => $b]);
    }

    /** Sürükle-bırak sonucu: id listesi yeni sıra. @param  array<int, int>  $ids */
    public function reorder(User $actor, Website $website, array $ids): void
    {
        $draft = $this->draft($website)->keyBy('id');
        $position = 1;

        foreach ($ids as $id) {
            if ($draft->has((int) $id)) {
                $draft[(int) $id]->forceFill(['sort_order' => $position++])->save();
            }
        }

        foreach ($draft as $section) {
            if (! in_array($section->id, array_map('intval', $ids), true)) {
                $section->forceFill(['sort_order' => $position++])->save();
            }
        }

        $this->audit->record($actor, 'site.sections_reordered', 'website', $website->id, [], ['order' => array_map('intval', $ids)]);
    }

    public function duplicate(User $actor, Website $website, SiteSection $section): SiteSection
    {
        $def = SectionLibrary::type($section->type);

        if ($def['unique'] ?? false) {
            throw new DomainException($def['label'].' bölümü çoğaltılamaz (tek olabilir).');
        }

        $this->shift($website, $section->sort_order + 1);
        $copy = $section->replicate(['updated_by']);
        $copy->forceFill(['sort_order' => $section->sort_order + 1, 'anchor' => $this->uniqueAnchor($website, $section->type), 'updated_by' => $actor->id])->save();
        $this->audit->record($actor, 'site.section_duplicated', 'site_section', $copy->id, [], ['from' => $section->id]);

        return $copy;
    }

    public function toggle(User $actor, Website $website, SiteSection $section): void
    {
        $before = ['is_visible' => $section->is_visible];
        $section->forceFill(['is_visible' => ! $section->is_visible, 'updated_by' => $actor->id])->save();
        $this->audit->record($actor, 'site.section_toggled', 'site_section', $section->id, $before, ['is_visible' => $section->is_visible]);
    }

    public function delete(User $actor, Website $website, SiteSection $section): void
    {
        $before = $section->toSnapshot();
        $section->delete();
        $this->audit->record($actor, 'site.section_deleted', 'site_section', $section->id, $before, []);
    }

    // ---- Yayın / revizyon --------------------------------------------------------

    /** Taslağı anlık görüntü olarak yayınlar; site önbelleği düşer. */
    public function publish(User $actor, Website $website, ?string $note = null): SiteRevision
    {
        $snapshot = $this->draft($website)->map(fn (SiteSection $s) => $s->toSnapshot())->values()->all();

        $revision = DB::transaction(function () use ($website, $snapshot, $note, $actor) {
            $number = (int) SiteRevision::query()->where('website_id', $website->id)->lockForUpdate()->max('number') + 1;

            return SiteRevision::query()->create(['website_id' => $website->id, 'number' => $number, 'snapshot' => $snapshot, 'note' => $note !== null && trim($note) !== '' ? trim($note) : null, 'created_by' => $actor->id, 'published_at' => Carbon::now()]);
        });

        $this->cache->invalidate($website);
        $this->audit->record($actor, 'site.published', 'website', $website->id, ['revision' => $this->latest($website, $revision->id)?->number], ['revision' => $revision->number, 'sections' => count($snapshot)]);

        return $revision;
    }

    /** Eski revizyonu taslağa kopyalar ve yeni revizyon olarak yayınlar. */
    public function rollback(User $actor, Website $website, SiteRevision $revision): SiteRevision
    {
        if ((int) $revision->website_id !== (int) $website->id) {
            throw new DomainException('Revizyon bu siteye ait değil.');
        }

        DB::transaction(function () use ($website, $revision, $actor) {
            SiteSection::query()->where('website_id', $website->id)->delete();

            foreach ((array) $revision->snapshot as $i => $row) {
                if (! is_array($row) || ! SectionLibrary::exists((string) ($row['type'] ?? ''))) {
                    continue;
                }

                SiteSection::query()->create([
                    'website_id' => $website->id, 'type' => $row['type'], 'anchor' => $row['anchor'] ?? null, 'sort_order' => $i + 1,
                    'is_visible' => (bool) ($row['is_visible'] ?? true), 'hide_on_mobile' => (bool) ($row['hide_on_mobile'] ?? false), 'hide_on_desktop' => (bool) ($row['hide_on_desktop'] ?? false),
                    'settings' => (array) ($row['settings'] ?? []), 'publish_from' => $row['publish_from'] ?? null, 'publish_until' => $row['publish_until'] ?? null, 'updated_by' => $actor->id,
                ]);
            }
        });

        $this->audit->record($actor, 'site.rolled_back', 'website', $website->id, [], ['to_revision' => $revision->number]);

        return $this->publish($actor, $website, 'Geri alma: revizyon '.$revision->number);
    }

    /** @return Collection<int, SiteRevision> */
    public function revisions(Website $website, int $limit = 30): Collection
    {
        return SiteRevision::query()->with('author')->where('website_id', $website->id)->orderByDesc('number')->limit($limit)->get();
    }

    public function findRevision(Website $website, int $id): SiteRevision
    {
        $revision = SiteRevision::query()->where('website_id', $website->id)->find($id);

        if ($revision === null) {
            throw new DomainException('Revizyon bulunamadı.');
        }

        return $revision;
    }

    private function latest(Website $website, int $excludeId): ?SiteRevision
    {
        return SiteRevision::query()->where('website_id', $website->id)->where('id', '!=', $excludeId)->orderByDesc('number')->first();
    }

    /** Taslak ile son yayın farklı mı (panel "yayınlanmamış değişiklik" rozeti). */
    public function hasUnpublishedChanges(Website $website): bool
    {
        $latest = SiteRevision::query()->where('website_id', $website->id)->orderByDesc('number')->first();
        $draft = $this->draft($website)->map(fn (SiteSection $s) => $s->toSnapshot())->values()->all();

        return $latest === null || $latest->snapshot !== $draft;
    }

    // ---- Vitrin ------------------------------------------------------------------

    /**
     * Vitrinde basılacak bölümler: yayınlanmış revizyon (önbellekli) ya da varsayılan yerleşim;
     * görünürlük + zamanlama + cihaz süzgeci uygulanmış.
     *
     * @return array<int, array{type: string, anchor: string|null, settings: array<string, mixed>, hide_on_mobile: bool, hide_on_desktop: bool}>
     */
    public function published(Website $website): array
    {
        $rows = $this->cache->remember($website, 'sections', function () use ($website): array {
            $latest = SiteRevision::query()->where('website_id', $website->id)->orderByDesc('number')->first();

            return $latest === null
                ? array_map(fn (array $r) => $r + ['is_visible' => true, 'hide_on_mobile' => false, 'hide_on_desktop' => false, 'settings' => [], 'publish_from' => null, 'publish_until' => null], SectionLibrary::defaultLayout())
                : (array) $latest->snapshot;
        });

        return $this->renderable(is_array($rows) ? $rows : []);
    }

    /** Taslağın vitrin görünümü (önizleme). @return array<int, array{type: string, anchor: string|null, settings: array<string, mixed>, hide_on_mobile: bool, hide_on_desktop: bool}> */
    public function draftForPreview(Website $website): array
    {
        return $this->renderable($this->draft($website)->map(fn (SiteSection $s) => $s->toSnapshot())->values()->all());
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<int, array{type: string, anchor: string|null, settings: array<string, mixed>, hide_on_mobile: bool, hide_on_desktop: bool}>
     */
    private function renderable(array $rows): array
    {
        $now = Carbon::now();
        $out = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! SectionLibrary::exists((string) ($row['type'] ?? '')) || ! ($row['is_visible'] ?? true)) {
                continue;
            }

            if (! empty($row['publish_from']) && Carbon::parse($row['publish_from'])->greaterThan($now)) {
                continue;
            }

            if (! empty($row['publish_until']) && Carbon::parse($row['publish_until'])->lessThanOrEqualTo($now)) {
                continue;
            }

            $out[] = ['type' => (string) $row['type'], 'anchor' => isset($row['anchor']) ? (string) $row['anchor'] : null, 'settings' => (array) ($row['settings'] ?? []), 'hide_on_mobile' => (bool) ($row['hide_on_mobile'] ?? false), 'hide_on_desktop' => (bool) ($row['hide_on_desktop'] ?? false)];
        }

        return $out;
    }

    /** İmzalı, süreli önizleme adresi (taslak). */
    public function previewUrl(Website $website): string
    {
        return URL::temporarySignedRoute('site.preview', Carbon::now()->addMinutes(self::PREVIEW_MINUTES), ['website' => $website->id]);
    }

    /**
     * CTA çözümü (§29): eylem tipi → href. Site ayarı olmayan telefon/WhatsApp/e-posta → null (düğme basılmaz).
     *
     * @param  array<string, mixed>|null  $cta
     * @return array{href: string, label: string, external: bool}|null
     */
    public function cta(?array $cta, Website $website): ?array
    {
        if ($cta === null || ($cta['action'] ?? 'none') === 'none') {
            return null;
        }

        $brand = $website->brand();
        $target = trim((string) ($cta['target'] ?? ''));
        $label = trim((string) ($cta['label'] ?? ''));

        $href = match ((string) $cta['action']) {
            'anchor' => $target !== '' ? '#'.ltrim($target, '#') : null,
            'page' => $target !== '' ? '/'.ltrim($target, '/') : null,
            'booking' => route('site.booking.index', [], false),
            'lead_form' => '#teklif',
            'locations' => route('site.locations', [], false),
            'blog' => route('site.posts', [], false),
            'phone' => $brand['phone_href'] !== '' ? $brand['phone_href'] : null,
            'whatsapp' => $brand['whatsapp_href'] !== '' ? $brand['whatsapp_href'] : null,
            'email' => $brand['email'] !== '' ? 'mailto:'.$brand['email'] : null,
            'url' => str_starts_with($target, 'https://') ? $target : null,
            default => null,
        };

        if ($href === null || $label === '') {
            return null;
        }

        return ['href' => $href, 'label' => $label, 'external' => str_starts_with($href, 'http')];
    }

    /** @param  array<string, mixed>  $cta  @return array{action: string, target: string, label: string} */
    private function normalizeCta(array $cta): array
    {
        $action = isset(SectionLibrary::CTA_ACTIONS[(string) ($cta['action'] ?? '')]) ? (string) $cta['action'] : 'none';
        $target = trim((string) ($cta['target'] ?? ''));

        if ($action === 'url' && $target !== '' && ! str_starts_with($target, 'https://')) {
            throw new DomainException('Dış bağlantı https:// ile başlamalı.');
        }

        if ($action === 'page' && $target !== '' && preg_match('~^/?[a-z0-9-]+(/[a-z0-9-]+)?$~', $target) !== 1) {
            throw new DomainException('Site sayfası yolu slug biçiminde olmalı (örn. hakkimizda).');
        }

        return ['action' => $action, 'target' => mb_substr($target, 0, 200), 'label' => mb_substr(trim((string) ($cta['label'] ?? '')), 0, 60)];
    }

    private function shift(Website $website, int $fromPosition): void
    {
        SiteSection::query()->where('website_id', $website->id)->where('sort_order', '>=', $fromPosition)->increment('sort_order');
    }

    private function uniqueAnchor(Website $website, string $type): string
    {
        $base = str_replace('_', '-', $type);
        $existing = SiteSection::query()->where('website_id', $website->id)->pluck('anchor')->filter()->all();
        $anchor = $base;

        for ($i = 2; in_array($anchor, $existing, true); $i++) {
            $anchor = $base.'-'.$i;
        }

        return $anchor;
    }

    private function dateOrNull(mixed $value): ?Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            throw new DomainException('Tarih biçimi geçersiz.');
        }
    }
}
