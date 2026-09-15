<?php

namespace App\Http\Requests;

use App\Services\JitAccessService;
use Illuminate\Foundation\Http\FormRequest;

class RequestJitAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // yetki route middleware'inde (permission:kyc.view_status,company)
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            // Gerekçe denetim izidir: "bak" gibi tek kelime kabul edilmez.
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'ttl_minutes' => ['required', 'integer', 'min:5', 'max:'.JitAccessService::MAX_TTL_MINUTES],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.min' => 'Gerekçe en az 10 karakter olmalı; denetimde okunacak.',
        ];
    }
}
