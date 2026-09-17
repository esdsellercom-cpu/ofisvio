<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Vitrin rezervasyon talebi (herkese açık). KVKK rızası zorunlu; bot tuzağı;
 * telefon E.164 (WhatsApp/SMS teyidi için). İş kuralları BookingService'te.
 */
class StorePublicBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // herkese açık; throttle route'ta
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date_format:Y-m-d'],
            'start' => ['required', 'date_format:H:i'],
            'hours' => ['required', 'numeric', 'min:0.5', 'max:24'],
            'participants' => ['nullable', 'integer', 'min:1', 'max:500'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'company_name' => ['nullable', 'string', 'max:160'],
            'note' => ['nullable', 'string', 'max:300'],
            'kvkk' => ['accepted'],
            'website' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'start.required' => 'Bir saat seçin.',
            'phone.regex' => 'Telefonu ülke koduyla girin (+905…).',
            'kvkk.accepted' => 'Devam etmek için KVKK aydınlatma metnini onaylamanız gerekir.',
            'website.prohibited' => 'İstek işlenemedi.',
        ];
    }
}
