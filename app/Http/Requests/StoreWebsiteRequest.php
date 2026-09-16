<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWebsiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // yetki route middleware'inde (permission:website.manage)
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $ignore = $this->route('website')?->id;

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/'],
            // Yalnızca alan adı (şema/yol yok); tekil; küçük harfe indirilir.
            'domain' => [
                'nullable', 'string', 'max:253',
                'regex:/^(?=.{1,253}$)(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/i',
                Rule::unique('websites', 'domain')->ignore($ignore)->whereNull('deleted_at'),
            ],
            'organization_id' => ['nullable', 'integer', Rule::exists('organizations', 'id')->whereNull('deleted_at')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'domain.regex' => 'Alan adı "ornek.com" biçiminde olmalı; http:// ya da yol içermemeli.',
            'domain.unique' => 'Bu alan adı başka bir siteye atanmış.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'Site adı', 'slug' => 'Slug', 'domain' => 'Alan adı', 'organization_id' => 'Organizasyon'];
    }
}
