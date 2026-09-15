<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewKycDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // yetki route middleware'inde
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approve', 'reject', 'request_more_info'])],
            // Red ve ek bilgi talebinde gerekçe ZORUNLU: müşteriye "neden"
            // söylenmeden reddedilen bir belge, çözülemeyen bir destek talebine
            // dönüşür.
            'note' => ['nullable', 'string', 'max:2000', 'required_unless:decision,approve'],
        ];
    }

    public function messages(): array
    {
        return [
            'note.required_unless' => 'Red ve ek bilgi talebinde gerekçe zorunludur.',
        ];
    }
}
