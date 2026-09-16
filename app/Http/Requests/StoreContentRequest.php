<?php

namespace App\Http\Requests;

use App\Enums\ContentKind;
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
        $creating = $this->routeIs('panel.content.store');

        return [
            'kind' => $creating ? ['required', Rule::enum(ContentKind::class)] : ['prohibited'],
            'title' => ['required', 'string', 'min:3', 'max:190'],
            'slug' => ['nullable', 'string', 'max:190', 'regex:/^[a-z0-9-]+$/'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body' => ['nullable', 'string', 'max:200000'],
            'category' => ['nullable', 'string', 'max:80'],
            'requires_approval' => ['sometimes', 'boolean'],
            'meta_title' => ['nullable', 'string', 'max:70'],
            'meta_description' => ['nullable', 'string', 'max:160'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.regex' => 'Slug yalnızca küçük harf, rakam ve tire içerebilir.',
            'kind.prohibited' => 'İçerik türü sonradan değiştirilemez.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'kind' => 'İçerik türü', 'title' => 'Başlık', 'slug' => 'Slug', 'excerpt' => 'Özet',
            'body' => 'Gövde', 'category' => 'Kategori', 'meta_title' => 'SEO başlığı',
            'meta_description' => 'SEO açıklaması', 'requires_approval' => 'Onay gerekli',
        ];
    }
}
