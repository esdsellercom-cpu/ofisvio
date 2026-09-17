<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rezervasyon formu (müşteri + resepsiyon masası). İş kuralları (açık saat,
 * çakışma, ufuk) BookingService'te; burada yalnız biçim.
 */
class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // yetki route middleware'inde (permission:booking.create,…)
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer', 'min:1'],
            'company_id' => ['sometimes', 'integer', 'min:1'], // yalnız resepsiyon masası
            'date' => ['required', 'date_format:Y-m-d'],
            'start' => ['required', 'date_format:H:i'],
            'hours' => ['required', 'numeric', 'min:0.5', 'max:24'],
            'note' => ['nullable', 'string', 'max:300'],
            'participants' => ['nullable', 'integer', 'min:1', 'max:500'],
            'override' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'date.date_format' => 'Tarih YYYY-AA-GG biçiminde olmalı.',
            'start.date_format' => 'Saat SS:DD biçiminde olmalı.',
        ];
    }
}
