<?php

namespace App\Http\Requests;

use App\Enums\ContentKind;
use App\Services\ContentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // yetki route middleware'inde (permission:content.create / content.edit)
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        // Oluşturma rotaları: kaydet (store) ve kaydet-ve-yayınla (faz 48). Güncellemede website/kind yasak.
        $creating = $this->routeIs('panel.content.store', 'panel.content.store.publish');

        return [
            'website_id' => $creating ? ['required', 'integer', Rule::exists('websites', 'id')->whereNull('deleted_at')] : ['prohibited'],
            'kind' => $creating ? ['required', Rule::enum(ContentKind::class)] : ['prohibited'],
            'title' => ['required', 'string', 'min:3', 'max:190'],
            'slug' => ['nullable', 'string', 'max:190', 'regex:/^[a-z0-9-]+$/'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body' => ['nullable', 'string', 'max:200000'],
            'category' => ['nullable', 'string', 'max:80'],
            'parent_id' => ['nullable', 'integer'], // sayfa ebeveyni; site/tür/seviye kontrolü serviste
            'cover_media_id' => ['nullable', 'integer'], // aynı sitenin medyası; kontrol serviste
            'tags' => ['nullable', 'string', 'max:300'], // virgülle ayrılmış; serviste normalize edilir
            'requires_approval' => ['sometimes', 'boolean'],
            'noindex' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'meta_title' => ['nullable', 'string', 'max:70'],
            'meta_description' => ['nullable', 'string', 'max:160'],
            // CMS stüdyo (faz 48): SEO / GEO / şema — eski formlar göndermezse dokunulmaz.
            'focus_keyword' => ['nullable', 'string', 'max:120'],
            'related_keywords' => ['nullable', 'string', 'max:300'],
            'canonical_url' => ['nullable', 'string', 'max:500', 'regex:#^(https://|/)[^\s]*$#'],
            'robots' => ['nullable', Rule::in(['', 'index, follow', 'noindex, follow', 'index, nofollow', 'noindex, nofollow'])],
            'og_title' => ['nullable', 'string', 'max:120'],
            'og_description' => ['nullable', 'string', 'max:300'],
            'og_media_id' => ['nullable', 'integer'],
            'geo' => ['nullable', 'array'],
            'geo.*' => ['nullable', 'string', 'max:5000'],
            'schema_types' => ['nullable', 'array'],
            'schema_types.*' => ['string', Rule::in(ContentService::SCHEMA_TYPES)],
            'schema_custom' => ['nullable', 'string', 'max:20000'],
            'then' => ['nullable', Rule::in(['save', 'preview', 'publish'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.regex' => 'Slug yalnızca küçük harf, rakam ve tire içerebilir.',
            'kind.prohibited' => 'İçerik türü sonradan değiştirilemez.',
            'website_id.prohibited' => 'İçerik başka siteye taşınamaz.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'website_id' => 'Site', 'kind' => 'İçerik türü', 'title' => 'Başlık', 'slug' => 'Slug', 'excerpt' => 'Özet',
            'body' => 'Gövde', 'category' => 'Kategori', 'tags' => 'Etiketler', 'meta_title' => 'SEO başlığı',
            'meta_description' => 'SEO açıklaması', 'requires_approval' => 'Onay gerekli',
            'focus_keyword' => 'Odak anahtar kelime', 'canonical_url' => 'Canonical adres', 'og_title' => 'OG başlığı', 'og_description' => 'OG açıklaması', 'schema_custom' => 'Özel JSON-LD',
        ];
    }
}
