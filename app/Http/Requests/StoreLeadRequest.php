<?php

namespace App\Http\Requests;

use App\Services\CurrentWebsite;
use App\Services\ServiceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // herkese açık form
    }

    public function rules(): array
    {
        return [
            // Ön rezervasyon (booking) artık lead değil: gerçek rezervasyon akışı (/rezervasyon). Eski kayıtlar panelde okunur.
            'kind' => ['required', Rule::in(['quote'])],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('is_published', true)],
            // Çözüm seçenekleri Hizmetler modülünden (aktif hizmet adları); config'te ticari liste yok.
            'solution' => ['nullable', 'string', Rule::in(app(ServiceService::class)->names(app(CurrentWebsite::class)->get()))],
            'team_size' => ['nullable', Rule::in(array_keys(config('ofisvio.team_sizes')))],
            'requested_date' => ['nullable', 'date', 'after_or_equal:today'],
            'requested_slot' => ['nullable', 'string', 'max:8'],
            'note' => ['nullable', 'string', 'max:1000'],

            // KVKK açık rızası ZORUNLU ve "accepted" ile doğrulanır:
            // işaretlenmemiş bir kutu isteğe hiç gelmez, nullable bırakmak
            // rızasız kayıt açardı.
            'kvkk' => ['accepted'],

            // Bot tuzağı: gerçek kullanıcıya görünmeyen alan. Doluysa istek
            // sessizce reddedilir (captcha eklenene kadarki ilk savunma).
            'website' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Ad soyad zorunludur.',
            'email.required' => 'E-posta zorunludur.',
            'email.email' => 'Geçerli bir e-posta adresi girin.',
            'kvkk.accepted' => 'Devam etmek için KVKK aydınlatma metnini onaylamanız gerekir.',
            'requested_date.after_or_equal' => 'Geçmiş bir tarih için rezervasyon alınamaz.',
            'location_id.exists' => 'Seçilen lokasyon bulunamadı.',
            'website.prohibited' => 'İstek işlenemedi.',
        ];
    }
}
