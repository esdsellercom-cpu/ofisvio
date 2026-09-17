<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Vitrin etkinlik kaydı: KVKK açık rızası zorunlu, bot tuzağı (website) dolu ise reddedilir. */
class StoreEventRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // herkese açık form
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'company_name' => ['nullable', 'string', 'max:160'],
            'note' => ['nullable', 'string', 'max:500'],
            'kvkk' => ['accepted'],
            'website' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['kvkk.accepted' => 'Devam etmek için KVKK aydınlatma metnini onaylamanız gerekir.', 'website.prohibited' => 'İstek işlenemedi.'];
    }
}
