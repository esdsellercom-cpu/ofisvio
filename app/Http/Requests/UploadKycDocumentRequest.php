<?php

namespace App\Http\Requests;

use App\Enums\KycDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadKycDocumentRequest extends FormRequest
{
    /**
     * Yetki kontrolü route middleware'inde (permission:kyc.upload,company)
     * yapılır. Burada true dönmek "herkes yükleyebilir" demek DEĞİLDİR;
     * yetki katmanının tek yerde kalması içindir — iki yerde yapılan kontrol,
     * birinin unutulduğu gün sessizce açılır.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(KycDocumentType::class)],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Belge tipi zorunludur.',
            'file.required' => 'Belge dosyası zorunludur.',
            'file.max' => 'Belge 10 MB sınırını aşamaz.',
            'file.mimes' => 'Yalnızca PDF, JPG ve PNG kabul edilir.',
        ];
    }

    public function documentType(): KycDocumentType
    {
        return KycDocumentType::from($this->validated()['type']);
    }
}
