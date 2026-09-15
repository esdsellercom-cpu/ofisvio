<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpenOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // yetki route middleware'inde (permission:user.manage)
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'organization_name' => ['required', 'string', 'min:2', 'max:190'],
            'owner_name' => ['required', 'string', 'min:2', 'max:120'],
            // 'dns' kuralı YOK: doğrulama ağa bağlı olmasın (CI/test çevrimdışı).
            'owner_email' => ['required', 'email:rfc', 'max:190'],
        ];
    }
}
