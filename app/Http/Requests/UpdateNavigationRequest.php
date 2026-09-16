<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Menü düzeni: nav[<sayfa id>][order|show]. Yetki route'ta (content.edit);
 * sayfa-site eşlemesi serviste süzülür.
 */
class UpdateNavigationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'nav' => ['required', 'array', 'max:200'],
            'nav.*.order' => ['nullable', 'integer', 'min:1', 'max:999'],
            'nav.*.show' => ['required', 'boolean'],
        ];
    }

    /** @return array<int, array{order: int|null, show: bool}> */
    public function layout(): array
    {
        $out = [];

        foreach ($this->validated('nav') as $id => $row) {
            if (! is_numeric($id)) {
                continue;
            }

            $out[(int) $id] = [
                'order' => isset($row['order']) && $row['order'] !== '' ? (int) $row['order'] : null,
                'show' => (bool) $row['show'],
            ];
        }

        return $out;
    }
}
