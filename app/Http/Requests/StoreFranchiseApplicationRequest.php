<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Vitrin franchise başvurusu: KVKK açık rızası zorunlu, bot tuzağı (website). */
class StoreFranchiseApplicationRequest extends FormRequest
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
            'city' => ['required', 'string', 'max:80'],
            'district' => ['nullable', 'string', 'max:80'],
            'budget' => ['nullable', 'string', 'max:60'],
            'experience' => ['nullable', 'string', 'max:2000'],
            'message' => ['nullable', 'string', 'max:2000'],
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
