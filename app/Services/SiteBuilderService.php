<?php

namespace App\Services;

use App\Enums\ContentKind;
use App\Models\Content;
use App\Models\Media;
use App\Models\SiteBlockPreset;
use App\Models\SiteRevision;
use App\Models\SiteSection;
use App\Models\User;
use App\Models\Website;
use App\Site\SectionLibrary;
use App\Site\SectionStyle;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
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

    /** Tek kayıtta en fazla bölüm. */
    public const MAX_SECTIONS = 40;

    public function __construct(
        private readonly ContentCache $cache,
        private readonly AuditService $audit,
        private readonly SiteBlockService $blocks,
        private readonly MediaService $media,
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
            // Varsayılan ayarlar (ör. franchise CTA) baştan kayıtta: editör durumu ile vitrin aynı; kaydedince kaybolmaz.
            SiteSection::query()->create(['website_id' => $website->id, 'type' => $row['type'], 'anchor' => $row['anchor'], 'sort_order' => $i + 1, 'is_visible' => true, 'settings' => SectionLibrary::defaults($row['type'])]);
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

        if ($draft->count() >= self::MAX_SECTIONS) {
            throw new DomainException('En fazla '.self::MAX_SECTIONS.' bölüm.');
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
        $settings = $this->normalizeSettings($section->type, (array) ($input['settings'] ?? []));

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
            'settings' => $settings,
            'publish_from' => $from,
            'publish_until' => $until,
            'updated_by' => $actor->id,
        ])->save();
        $this->audit->record($actor, 'site.section_updated', 'site_section', $section->id, $before, $section->toSnapshot());

        return $section;
    }

    /**
     * Bölüm ayarlarını kütüphane şemasına göre normalize eder (alan tipi başına); tasarım (SectionStyle) ve alan
     * biçimleri allowlist'ten geçer; tanımsız anahtar düşer. Görsel editör ve form aynı yoldan geçer.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalizeSettings(string $type, array $input): array
    {
        $def = SectionLibrary::type($type);
        $settings = [];

        foreach ($def['fields'] as $key => $field) {
            $value = $input[$key] ?? null;

            $settings[$key] = match ($field['type']) {
                'cta' => $this->normalizeCta(is_array($value) ? $value : []),
                'lines' => array_values(array_filter(array_map('trim', is_array($value) ? array_map('strval', $value) : (preg_split('/\r?\n/', (string) $value) ?: [])))),
                'select' => isset($field['options'][(string) $value]) ? (string) $value : (string) array_key_first($field['options'] ?? ['' => '']),
                'media' => is_numeric($value) && (int) $value > 0 ? (int) $value : '',
                'media_list' => array_values(array_filter(array_map('intval', is_array($value) ? $value : explode(',', (string) $value)), fn (int $id) => $id > 0)),
                'number' => is_numeric($value) ? (string) max(0, min(2000, (int) $value)) : '',
                'markdown' => mb_substr((string) $value, 0, 20000),
                default => mb_substr(trim((string) $value), 0, 2000),
            };
        }

        if ($type === 'blog') {
            $settings['limit'] = (string) max(1, min(6, (int) ($settings['limit'] ?: 3)));
        }

        if ($type === 'map' && $settings['embed'] !== '' && ! str_starts_with($settings['embed'], 'https://www.google.com/maps/embed')) {
            throw new DomainException('Harita gömme adresi https://www.google.com/maps/embed ile başlamalı.');
        }

        if ($type === 'image' && $settings['link'] !== '' && ! str_starts_with($settings['link'], '/') && ! str_starts_with($settings['link'], 'https://')) {
            throw new DomainException('Görsel bağlantısı / ya da https:// ile başlamalı.');
        }

        foreach (array_keys(SectionStyle::DEVICES) as $deviceKey) {
            $settings[$deviceKey] = SectionStyle::normalize($input[$deviceKey] ?? null);
        }

        $settings['field_styles'] = SectionStyle::normalizeFields($input['field_styles'] ?? null);

        return array_filter($settings, fn ($v) => $v !== '' && $v !== []);
    }

    // ---- Görsel editör (faz 49): tek kayıtta tüm taslak ----------------------------

    /**
     * Editörün gönderdiği tam taslağı uygular: sıra, ekleme (id yok), silme (listede yok), güncelleme;
     * global metin taslağı; bırakılan görseller (`upload:token` → medya). Tek işlemde, audit'li.
     *
     * @param  array<string, mixed>  $payload  {sections: [...], globals: {texts: {}, footer_columns: string}}
     * @param  array<string, mixed>  $uploads  token => UploadedFile
     * @param  bool  $siteImage  globals.hero_media ve globals.contact (site ayarları) uygulanır mı (website.manage; controller karar verir)
     * @return array{sections: int, created: int, deleted: int, uploaded: int}
     */
    public function applyDraft(User $actor, Website $website, array $payload, array $uploads = [], bool $siteImage = false): array
    {
        $rows = array_values(array_filter((array) ($payload['sections'] ?? []), 'is_array'));

        if (count($rows) > self::MAX_SECTIONS) {
            throw new DomainException('En fazla '.self::MAX_SECTIONS.' bölüm.');
        }

        $mediaByToken = [];

        foreach ($uploads as $token => $file) {
            if ($file instanceof UploadedFile && preg_match('/^[a-z0-9_-]{1,40}$/i', (string) $token) === 1) {
                $mediaByToken[(string) $token] = $this->media->upload($actor, $website, $file, ['alt' => mb_substr(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 0, 190)])->id;
            }
        }

        $existing = $this->draft($website)->keyBy('id');
        $presets = $this->presets($website)->keyBy('id');
        $presetOriginal = $presets->map(fn (SiteBlockPreset $p) => $p->settings ?? [])->all(); // aynı global bloğa bağlı birden çok bölüm: yalnız DEĞİŞEN yazar
        $updatedPresets = [];
        $seenTypes = [];
        $stats = ['sections' => 0, 'created' => 0, 'deleted' => 0, 'uploaded' => count($mediaByToken)];

        DB::transaction(function () use ($actor, $website, $rows, $existing, $presets, $presetOriginal, &$updatedPresets, $mediaByToken, &$seenTypes, &$stats, $payload, $siteImage) {
            $keep = [];
            $anchors = [];

            foreach ($rows as $i => $row) {
                $type = (string) ($row['type'] ?? '');

                if (! SectionLibrary::exists($type)) {
                    throw new DomainException('Tanımsız bölüm tipi: '.$type);
                }

                $def = SectionLibrary::type($type);

                if (($def['unique'] ?? false) && isset($seenTypes[$type])) {
                    throw new DomainException($def['label'].' bölümü sayfada yalnız bir kez olabilir.');
                }

                $seenTypes[$type] = true;
                $settings = $this->normalizeSettings($type, $this->resolveUploads((array) ($row['settings'] ?? []), $mediaByToken));
                $anchor = trim((string) ($row['anchor'] ?? ''));

                if ($anchor !== '' && preg_match('/^[a-z0-9-]{2,40}$/', $anchor) !== 1) {
                    throw new DomainException('Çapa yalnız küçük harf, rakam ve tire içerebilir: '.$anchor);
                }

                if ($anchor !== '' && isset($anchors[$anchor])) {
                    $anchor = $this->uniqueAnchor($website, $type, array_keys($anchors));
                }

                $from = $this->dateOrNull($row['publish_from'] ?? null);
                $until = $this->dateOrNull($row['publish_until'] ?? null);

                if ($from !== null && $until !== null && $until->lessThanOrEqualTo($from)) {
                    throw new DomainException('Bitiş, başlangıçtan sonra olmalı.');
                }

                // Kayıtlı bloğa bağlı bölüm: bağ yalnız aynı tipteki mevcut bloğa; GLOBAL blokta düzenleme bloğun kendisine
                // yazılır (tüm kullanımlar güncellenir), normal blok bağı yalnız kaynak bilgisi taşır.
                $presetId = (int) ($row['preset_id'] ?? 0);
                $preset = $presetId > 0 && $presets->has($presetId) && $presets[$presetId]->type === $type ? $presets[$presetId] : null;

                if ($preset !== null && $preset->is_global) {
                    if ($settings !== $presetOriginal[$preset->id]) {
                        $preset->forceFill(['settings' => $settings])->save();
                        $updatedPresets[$preset->id] = true;
                        $this->audit->record($actor, 'site.preset_updated', 'site_block_preset', $preset->id, ['settings' => $presetOriginal[$preset->id]], ['settings' => $settings, 'via' => 'editor']);
                    }

                    $settings = $preset->settings ?? []; // bağlı bölüm daima bloğun güncel ayarını taşır
                }

                $attributes = [
                    'type' => $type, 'anchor' => $anchor === '' ? null : $anchor, 'sort_order' => $i + 1, 'preset_id' => $preset?->id,
                    'is_visible' => filter_var($row['is_visible'] ?? true, FILTER_VALIDATE_BOOL),
                    'hide_on_mobile' => filter_var($row['hide_on_mobile'] ?? false, FILTER_VALIDATE_BOOL),
                    'hide_on_desktop' => filter_var($row['hide_on_desktop'] ?? false, FILTER_VALIDATE_BOOL),
                    'locked' => filter_var($row['locked'] ?? false, FILTER_VALIDATE_BOOL),
                    'label' => mb_substr(trim((string) ($row['label'] ?? '')), 0, 80) ?: null,
                    'settings' => $settings, 'publish_from' => $from, 'publish_until' => $until, 'updated_by' => $actor->id,
                ];
                $id = (int) ($row['id'] ?? 0);

                if ($id > 0 && $existing->has($id)) {
                    $section = $existing[$id];

                    if ($section->type !== $type) {
                        throw new DomainException('Bölüm tipi değiştirilemez; silip yeniden ekleyin.');
                    }

                    $section->fill($attributes)->save();
                } else {
                    $section = SiteSection::query()->create($attributes + ['website_id' => $website->id]);
                    $stats['created']++;
                }

                if ($anchor !== '') {
                    $anchors[$anchor] = true;
                }

                $keep[] = $section->id;
                $stats['sections']++;
            }

            foreach ($existing as $section) {
                if (! in_array($section->id, $keep, true)) {
                    $section->delete();
                    $stats['deleted']++;
                }
            }

            // Güncellenen global blok: aynı bloğa bağlı diğer bölümler de (bu kayıtta erken işlenenler dahil) eşitlenir.
            foreach (array_keys($updatedPresets) as $presetId) {
                SiteSection::query()->where('website_id', $website->id)->where('preset_id', $presetId)->update(['settings' => json_encode($presets[$presetId]->settings ?? [])]);
            }

            if (is_array($payload['globals'] ?? null)) {
                $this->saveGlobalsDraft($website, $payload['globals']);

                // Site ana görseli (hero_media_id): site ayarı, anında (taslak değil) — yalnız yetkili aktör.
                $hero = $this->resolveUploads(['m' => $payload['globals']['hero_media'] ?? ''], $mediaByToken)['m'];

                if ($siteImage && is_numeric($hero) && (int) $hero > 0 && Media::query()->where('website_id', $website->id)->whereKey((int) $hero)->exists()) {
                    $website->forceFill(['hero_media_id' => (int) $hero])->save();
                    $this->cache->invalidate($website);
                }

                // Site iletişim ayarı (telefon/WhatsApp/e-posta): site ayarı, anında — yalnız yetkili aktör; değişen alan audit'e yazılır.
                if ($siteImage && is_array($payload['globals']['contact'] ?? null)) {
                    $this->applyContact($actor, $website, $payload['globals']['contact']);
                }
            }
        });

        if ($updatedPresets !== []) {
            $this->cache->invalidate($website); // global blok yayındaki kullanımlarda da çizim anında değişir
        }

        $this->audit->record($actor, 'site.draft_applied', 'website', $website->id, [], $stats + ['global_presets' => array_keys($updatedPresets)]);

        return $stats;
    }

    /**
     * `upload:token` değerlerini yüklenen medya id'sine çevirir (media / media_list alanları).
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, int>  $mediaByToken
     * @return array<string, mixed>
     */
    private function resolveUploads(array $settings, array $mediaByToken): array
    {
        foreach ($settings as $key => $value) {
            if (is_string($value) && str_starts_with($value, 'upload:')) {
                $settings[$key] = $mediaByToken[substr($value, 7)] ?? '';
            } elseif (is_array($value)) {
                $settings[$key] = array_map(fn ($v) => is_string($v) && str_starts_with($v, 'upload:') ? ($mediaByToken[substr($v, 7)] ?? 0) : $v, $value);
            }
        }

        return $settings;
    }

    /**
     * Global (header/footer/üst şerit) metin taslağı: yayınlanana kadar yalnız önizlemede; yayın SiteBlockService'e yazar.
     *
     * Vitrin veri listeleri (dahil olanlar, planlar, plan satırları, fiyat notu) da buradadır: editörde ilgili bölüm
     * seçilince düzenlenir (`globals.blocks`), footer sütunları footer'da.
     *
     * @return array{texts: array<string, string>, footer_columns: string, blocks: array<string, string>}
     */
    public function globalsDraft(Website $website): array
    {
        $raw = (array) ($website->builder_globals ?? []);
        $blocks = [];

        foreach (self::DATA_BLOCKS as $key) {
            if (isset($raw['blocks'][$key])) {
                $blocks[$key] = (string) $raw['blocks'][$key];
            }
        }

        return ['texts' => array_map('strval', array_intersect_key((array) ($raw['texts'] ?? []), SiteBlockService::TEXT_KEYS)), 'footer_columns' => (string) ($raw['footer_columns'] ?? ''), 'blocks' => $blocks];
    }

    /** Editörde bölüm üstünden düzenlenen vitrin veri listeleri (SiteBlockService blokları; footer ayrı). */
    public const DATA_BLOCKS = ['amenities', 'plans', 'plan_rows', 'pricing_note'];

    /** Veri listesi → bağlı olduğu bölüm tipi (editör sağ paneli). */
    public const DATA_BLOCK_SECTIONS = ['amenities' => ['amenities'], 'pricing' => ['plans', 'plan_rows', 'pricing_note']];

    /** @param  array<string, mixed>  $globals */
    /** @param  array<string, mixed>  $contact */
    private function applyContact(User $actor, Website $website, array $contact): void
    {
        $limits = ['contact_phone' => 32, 'whatsapp_number' => 32, 'contact_email' => 190];
        $before = [];
        $after = [];

        foreach ($limits as $key => $max) {
            if (! array_key_exists($key, $contact)) {
                continue;
            }

            $value = mb_substr(trim((string) $contact[$key]), 0, $max);

            if ($key === 'contact_email' && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                throw new DomainException('E-posta adresi geçersiz.');
            }

            if ($value !== (string) ($website->{$key} ?? '')) {
                $before[$key] = $website->{$key};
                $after[$key] = $value === '' ? null : $value;
            }
        }

        if ($after === []) {
            return;
        }

        $website->forceFill($after)->save();
        $this->cache->invalidate($website);
        $this->audit->record($actor, 'website.settings_updated', 'website', $website->id, $before, $after);
    }

    private function saveGlobalsDraft(Website $website, array $globals): void
    {
        // Yalnız canlıdan FARKLI değerler taslağa yazılır; taslak boşsa "yayınla eşit" rozeti doğru kalır.
        $live = $this->blocks->texts($website);
        $texts = [];

        foreach (SiteBlockService::TEXT_KEYS as $key => $label) {
            if (array_key_exists($key, (array) ($globals['texts'] ?? []))) {
                $value = mb_substr(trim((string) $globals['texts'][$key]), 0, 500);

                if ($value !== ($live[$key] ?? '')) {
                    $texts[$key] = $value;
                }
            }
        }

        $footer = array_key_exists('footer_columns', $globals) ? mb_substr((string) $globals['footer_columns'], 0, 4000) : null;

        if ($footer !== null && trim($footer) === trim($this->blocks->text($website, 'footer_columns'))) {
            $footer = null;
        }

        $blocks = [];

        foreach (self::DATA_BLOCKS as $key) {
            if (array_key_exists($key, (array) ($globals['blocks'] ?? []))) {
                $value = mb_substr((string) $globals['blocks'][$key], 0, 8000);

                if (trim($value) !== trim($this->blocks->text($website, $key))) {
                    $this->blocks->parseOrFail($key, $value); // biçim hatası kaydı durdurur
                    $blocks[$key] = $value;
                }
            }
        }

        $draft = $texts === [] && $footer === null && $blocks === [] ? null : array_filter(['texts' => $texts, 'footer_columns' => $footer, 'blocks' => $blocks], fn ($v) => $v !== null && $v !== []);
        $website->forceFill(['builder_globals' => $draft ?: null])->save();
    }

    /** Yayın anında global taslağı canlıya yazar ve temizler (content.publish rotasından). */
    private function publishGlobals(User $actor, Website $website): void
    {
        $raw = $website->builder_globals;

        if (! is_array($raw) || $raw === []) {
            return;
        }

        if (is_array($raw['texts'] ?? null) && $raw['texts'] !== []) {
            $this->blocks->updateTexts($actor, $website, array_merge($this->blocks->texts($website), $raw['texts']));
        }

        if (isset($raw['footer_columns'])) {
            $this->blocks->update($actor, $website, 'footer_columns', (string) $raw['footer_columns']);
        }

        foreach ((array) ($raw['blocks'] ?? []) as $key => $text) {
            if (in_array($key, self::DATA_BLOCKS, true)) {
                $this->blocks->update($actor, $website, (string) $key, (string) $text);
            }
        }

        $website->forceFill(['builder_globals' => null])->save();
    }

    // ---- Kayıtlı bloklar ------------------------------------------------------------

    /** @return Collection<int, SiteBlockPreset> */
    public function presets(Website $website): Collection
    {
        return SiteBlockPreset::query()->where('website_id', $website->id)->orderBy('name')->get();
    }

    /** @param  array<string, mixed>  $settings  boş = tipin varsayılanı (kütüphaneden "yeni blok şablonu") */
    public function savePreset(User $actor, Website $website, string $name, string $type, array $settings, string $category = 'ozel', bool $global = false): SiteBlockPreset
    {
        if (! SectionLibrary::exists($type)) {
            throw new DomainException('Tanımsız bölüm tipi.');
        }

        $name = mb_substr(trim($name), 0, 80);

        if ($name === '') {
            throw new DomainException('Blok adı gerekli.');
        }

        if (SiteBlockPreset::query()->where('website_id', $website->id)->count() >= 60) {
            throw new DomainException('En fazla 60 kayıtlı blok.');
        }

        $preset = SiteBlockPreset::query()->create([
            'website_id' => $website->id, 'name' => $name, 'type' => $type, 'category' => isset(SiteBlockPreset::CATEGORIES[$category]) ? $category : 'ozel', 'is_global' => $global,
            'settings' => $this->normalizeSettings($type, $settings === [] ? SectionLibrary::defaults($type) : $settings), 'created_by' => $actor->id,
        ]);
        $this->audit->record($actor, 'site.preset_saved', 'site_block_preset', $preset->id, [], ['type' => $type, 'name' => $name, 'category' => $preset->category, 'global' => $global]);

        return $preset;
    }

    public function findPreset(Website $website, int $id): SiteBlockPreset
    {
        $preset = SiteBlockPreset::query()->where('website_id', $website->id)->find($id);

        if ($preset === null) {
            throw new DomainException('Kayıtlı blok bulunamadı.');
        }

        return $preset;
    }

    /** Ad / kategori / global bayrağı (kütüphane). Global kapatılınca bağlı bölümler kendi kopyalarıyla kalır. */
    public function updatePreset(User $actor, Website $website, int $id, string $name, string $category, bool $global): SiteBlockPreset
    {
        $preset = $this->findPreset($website, $id);
        $name = mb_substr(trim($name), 0, 80);

        if ($name === '') {
            throw new DomainException('Blok adı gerekli.');
        }

        $before = ['name' => $preset->name, 'category' => $preset->category, 'is_global' => $preset->is_global];
        $preset->fill(['name' => $name, 'category' => isset(SiteBlockPreset::CATEGORIES[$category]) ? $category : 'ozel', 'is_global' => $global])->save();
        $this->cache->invalidate($website); // global blok vitrinde çizim anında çözülür
        $this->audit->record($actor, 'site.preset_updated', 'site_block_preset', $preset->id, $before, ['name' => $name, 'category' => $preset->category, 'is_global' => $global]);

        return $preset;
    }

    public function duplicatePreset(User $actor, Website $website, int $id): SiteBlockPreset
    {
        $source = $this->findPreset($website, $id);

        return $this->savePreset($actor, $website, mb_substr($source->name.' (kopya)', 0, 80), $source->type, $source->settings ?? [], $source->category, false);
    }

    /** Siler; bağlı bölümler bloğun son ayarını kendi kopyası olarak alır (sayfa bozulmaz). */
    public function deletePreset(User $actor, Website $website, int $id): void
    {
        $preset = $this->findPreset($website, $id);

        DB::transaction(function () use ($preset) {
            foreach ($preset->sections as $section) {
                $section->forceFill(['preset_id' => null, 'settings' => $preset->is_global ? ($preset->settings ?? []) : ($section->settings ?? [])])->save();
            }

            $preset->delete();
        });
        $this->cache->invalidate($website);
        $this->audit->record($actor, 'site.preset_deleted', 'site_block_preset', $id, ['name' => $preset->name], []);
    }

    /**
     * Kütüphane kullanım raporu: kayıtlı blok → taslak/yayın bölümleri; bileşen tipi → ana sayfa bölümü ve
     * gövdesinde aynı adlı CMS bloğu (:::tip) geçen sayfalar.
     *
     * @return array{presets: array<int, array{draft: array<int, int>, published: int}>, types: array<string, array{section: int|null, pages: array<int, array{id: int, title: string}>}>}
     */
    public function usage(Website $website): array
    {
        $draft = $this->draft($website);
        $latest = SiteRevision::query()->where('website_id', $website->id)->orderByDesc('number')->first();
        $published = collect($latest === null ? [] : (array) $latest->snapshot);
        $out = ['presets' => [], 'types' => []];

        foreach ($this->presets($website) as $preset) {
            $out['presets'][$preset->id] = [
                'draft' => $draft->where('preset_id', $preset->id)->pluck('id')->values()->all(),
                'published' => $published->where('preset_id', $preset->id)->count(),
            ];
        }

        $pages = Content::query()->where('website_id', $website->id)->where('kind', ContentKind::PAGE->value)->where('body', 'like', '%:::%')->get(['id', 'title', 'body']);

        foreach (array_keys(SectionLibrary::types()) as $type) {
            $out['types'][$type] = [
                'section' => $draft->firstWhere('type', $type)?->id,
                'pages' => $pages->filter(fn (Content $p) => str_contains((string) $p->body, ':::'.$type))->map(fn (Content $p) => ['id' => $p->id, 'title' => $p->title])->values()->all(),
            ];
        }

        return $out;
    }

    /**
     * Editör şablonları: eklenebilir her tip için varsayılan ayarla çizilecek satır (iframe'de <template> olur).
     *
     * @return array<int, array{type: string, anchor: string|null, settings: array<string, mixed>, hide_on_mobile: bool, hide_on_desktop: bool, media: array<int, array{url: string, alt: string}>}>
     */
    public function templates(Website $website): array
    {
        $rows = [];

        foreach (SectionLibrary::types() as $type => $def) {
            $rows[] = ['type' => $type, 'anchor' => null, 'is_visible' => true, 'settings' => SectionLibrary::defaults($type)];
        }

        foreach ($this->presets($website) as $preset) {
            $rows[] = ['type' => $preset->type, 'anchor' => null, 'is_visible' => true, 'settings' => $preset->settings ?? [], 'preset' => $preset->id, 'preset_id' => $preset->is_global ? $preset->id : null];
        }

        return $this->renderable($rows, true);
    }

    /** Kütüphane blok önizlemesi (`?preset=ID`): yalnız o blok, gerçek bileşenle. @return array<int, array<string, mixed>> */
    public function presetForPreview(Website $website, int $id): array
    {
        $preset = SiteBlockPreset::query()->where('website_id', $website->id)->find($id);

        return $preset === null ? [] : $this->renderable([['type' => $preset->type, 'anchor' => null, 'is_visible' => true, 'settings' => $preset->settings ?? [], 'preset' => $preset->id]], true);
    }

    /** Eski revizyonun vitrin görünümü (önizleme `?revision=N`). @return array<int, array<string, mixed>> */
    public function revisionForPreview(Website $website, int $number): array
    {
        $revision = SiteRevision::query()->where('website_id', $website->id)->where('number', $number)->first();

        return $revision === null ? [] : $this->renderable((array) $revision->snapshot);
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

        $this->publishGlobals($actor, $website);
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
                    'locked' => (bool) ($row['locked'] ?? false), 'label' => isset($row['label']) ? (string) $row['label'] : null,
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

        return $latest === null || $latest->snapshot !== $draft || ! empty($website->builder_globals);
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
                ? array_map(fn (array $r) => $r + ['is_visible' => true, 'hide_on_mobile' => false, 'hide_on_desktop' => false, 'settings' => SectionLibrary::defaults($r['type']), 'publish_from' => null, 'publish_until' => null], SectionLibrary::defaultLayout())
                : (array) $latest->snapshot;
        });

        return $this->renderable(is_array($rows) ? $rows : []);
    }

    /** Taslağın vitrin görünümü (önizleme). @return array<int, array{type: string, anchor: string|null, settings: array<string, mixed>, hide_on_mobile: bool, hide_on_desktop: bool}> */
    public function draftForPreview(Website $website): array
    {
        return $this->renderable($this->draft($website)->map(fn (SiteSection $s) => $s->toSnapshot() + ['id' => $s->id])->values()->all());
    }

    /**
     * Editör çerçevesi: taslağın TÜM bölümleri (gizli/zamanlı dahil; editör bunları soluk gösterir) + id/kilit/etiket.
     *
     * @return array<int, array<string, mixed>>
     */
    public function draftForEditor(Website $website): array
    {
        return $this->renderable($this->draft($website)->map(fn (SiteSection $s) => $s->toSnapshot() + ['id' => $s->id])->values()->all(), true);
    }

    /**
     * @param  array<int, mixed>  $rows
     * @param  bool  $all  editör: görünürlük/zamanlama süzülmez
     * @return array<int, array{type: string, anchor: string|null, settings: array<string, mixed>, hide_on_mobile: bool, hide_on_desktop: bool, id: int|null, is_visible: bool, locked: bool, label: string|null, preset: int|null, style: string, style_class: string, media_css: string, media: array<int, array{url: string, alt: string, caption: string}>}>
     */
    private function renderable(array $rows, bool $all = false): array
    {
        $now = Carbon::now();
        $out = [];
        $mediaIds = [];
        $presetIds = array_values(array_unique(array_filter(array_map(fn ($r) => is_array($r) ? (int) ($r['preset_id'] ?? 0) : 0, $rows))));
        // Global kayıtlı blok: ayar çizim anında bloktan okunur → blok değişince her kullanım (yayın dahil) güncellenir.
        $globalPresets = $presetIds === [] ? collect() : SiteBlockPreset::query()->whereIn('id', $presetIds)->where('is_global', true)->get()->keyBy('id');

        foreach ($rows as $i => $row) {
            if (! is_array($row) || ! SectionLibrary::exists((string) ($row['type'] ?? ''))) {
                continue;
            }

            $linked = isset($row['preset_id']) && $globalPresets->has((int) $row['preset_id']) && $globalPresets[(int) $row['preset_id']]->type === $row['type'] ? $globalPresets[(int) $row['preset_id']] : null;

            if ($linked !== null) {
                $row['settings'] = $linked->settings ?? [];
            }

            if (! $all) {
                if (! ($row['is_visible'] ?? true)) {
                    continue;
                }

                if (! empty($row['publish_from']) && Carbon::parse($row['publish_from'])->greaterThan($now)) {
                    continue;
                }

                if (! empty($row['publish_until']) && Carbon::parse($row['publish_until'])->lessThanOrEqualTo($now)) {
                    continue;
                }
            }

            $settings = (array) ($row['settings'] ?? []);

            foreach (SectionLibrary::type((string) $row['type'])['fields'] as $key => $field) {
                if ($field['type'] === 'media' && ! empty($settings[$key])) {
                    $mediaIds[] = (int) $settings[$key];
                } elseif ($field['type'] === 'media_list') {
                    $mediaIds = array_merge($mediaIds, array_map('intval', (array) ($settings[$key] ?? [])));
                }
            }

            $id = isset($row['id']) ? (int) $row['id'] : null;
            $selector = '#sec-'.($id ?? ('t'.$i));
            $out[] = [
                'type' => (string) $row['type'], 'anchor' => isset($row['anchor']) ? (string) $row['anchor'] : null, 'settings' => $settings,
                'hide_on_mobile' => (bool) ($row['hide_on_mobile'] ?? false), 'hide_on_desktop' => (bool) ($row['hide_on_desktop'] ?? false),
                'id' => $id, 'is_visible' => (bool) ($row['is_visible'] ?? true), 'locked' => (bool) ($row['locked'] ?? false), 'label' => isset($row['label']) ? (string) $row['label'] : null, 'preset' => isset($row['preset']) ? (int) $row['preset'] : null,
                'preset_id' => isset($row['preset_id']) ? (int) $row['preset_id'] : null, 'preset_global' => $linked !== null,
                'style' => SectionStyle::inline((array) ($settings['style'] ?? [])), 'style_class' => SectionStyle::classes((array) ($settings['style'] ?? [])), 'media_css' => SectionStyle::media($settings, $selector), 'media' => [],
            ];
        }

        // Görsel/galeri bölümleri: medya tek sorguda; görünüm DB'ye gitmez.
        $mediaIds = array_values(array_unique(array_filter($mediaIds)));

        if ($mediaIds !== []) {
            $media = Media::query()->whereIn('id', $mediaIds)->get()->keyBy('id');

            foreach ($out as &$section) {
                foreach ($media as $m) {
                    $section['media'][$m->id] = ['url' => $m->url(), 'alt' => (string) $m->alt, 'caption' => (string) $m->caption];
                }
            }
            unset($section);
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

    /** @param  array<int, string>  $reserved */
    private function uniqueAnchor(Website $website, string $type, array $reserved = []): string
    {
        $base = str_replace('_', '-', $type);
        $existing = array_merge(SiteSection::query()->where('website_id', $website->id)->pluck('anchor')->filter()->all(), $reserved);
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
