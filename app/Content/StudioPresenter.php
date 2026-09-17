<?php

namespace App\Content;

use App\Enums\ContentKind;
use App\Models\Content;
use App\Models\ContentDraft;
use App\Models\Website;
use App\Services\ContentService;
use App\Services\GeoService;
use App\Services\SeoService;
use App\Services\ServiceService;

/**
 * CMS stüdyo (faz 48) editör verileri: iç bağlantı adayları (JS önerisi), SEO analizi, GEO önerileri (form alanı
 * boşsa doldurmak için — kaydedilmez), şema önizlemesi, bağlantı denetimi. Personel ve müşteri formları aynı veriyi alır.
 */
class StudioPresenter
{
    public function __construct(
        private readonly ContentService $contents,
        private readonly SeoService $seo,
        private readonly GeoService $geo,
        private readonly ServiceService $services,
    ) {}

    /**
     * @param  array<string, mixed>  $old  doğrulama hatasından dönen eski girdi (request()->old())
     * @return array<string, mixed>
     */
    public function build(Website $website, ?Content $content, ContentKind $kind, ?ContentDraft $draft = null, array $old = []): array
    {
        $src = $draft ?? $content;
        $val = fn (string $key, mixed $fallback = null) => array_key_exists($key, $old) ? $old[$key] : $fallback;
        $live = $this->contents->livePages($website)->merge($this->contents->livePosts($website, 200))->filter(fn (Content $c) => $content === null || $c->id !== $content->id)->values();
        $fields = [
            'title' => $val('title', $src?->title), 'slug' => $val('slug', $src?->slug), 'excerpt' => $val('excerpt', $src?->excerpt), 'body' => $val('body', $src?->body),
            'meta_title' => $val('meta_title', $src?->meta_title), 'meta_description' => $val('meta_description', $src?->meta_description),
            'focus_keyword' => $val('focus_keyword', $src?->focus_keyword), 'canonical_url' => $val('canonical_url', $src?->canonical_url),
            'schema_types' => $val('schema_types', $src !== null ? ($src->schema_types ?? []) : []), 'og_title' => $val('og_title', $src?->og_title), 'cover' => $val('cover_media_id', $content?->cover_media_id), 'kind' => $kind->value,
        ];

        return [
            'linkTargets' => $live->map(fn (Content $c) => ['title' => $c->title, 'path' => $c->path(), 'kind' => $c->kind->value])->values()->all(),
            'seo' => SeoAnalyzer::analyze($fields),
            'geoForm' => is_array($old['geo'] ?? null) ? array_replace(GeoSuggester::toForm($src?->geo), array_map('strval', $old['geo'])) : GeoSuggester::toForm($src?->geo),
            'geoSuggested' => GeoSuggester::suggest($fields, $live, $this->entities()),
            'geoFields' => GeoSuggester::FIELDS,
            'schemaTypes' => ContentService::SCHEMA_TYPES,
            'schemaPreview' => $content !== null ? json_encode($this->seo->head($website, $content)['json_ld'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'linkAudit' => $this->seo->linkAudit($website, (string) $fields['body'], $content),
            'related' => $content !== null ? $this->contents->linkSuggestions($content) : [],
            'blocks' => BodyRenderer::BLOCKS,
            'previewUrl' => $content !== null ? route('panel.content.preview', $content) : null,
        ];
    }

    /** Varlık önerileri için bilinen adlar: lokasyonlar ve hizmetler (uydurma değil, DB'den). @return array<int, string> */
    private function entities(): array
    {
        return $this->geo->allLocations()->pluck('name')->merge($this->services->active(null)->pluck('name'))->filter()->unique()->values()->all();
    }
}
