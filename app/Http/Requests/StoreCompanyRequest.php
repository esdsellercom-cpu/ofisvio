<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // yetki route middleware'inde (permission:organization.manage)
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'legal_name' => ['required', 'string', 'min:3', 'max:190'],
            // Türkiye VKN 10 hane, TCKN 11 hane; şahıs şirketleri TCKN ile kurulur.
            'tax_number' => ['nullable', 'string', 'regex:/^\d{10,11}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'tax_number.regex' => 'Vergi numarası 10 haneli VKN ya da 11 haneli TCKN olmalıdır.',
        ];
    }
}
