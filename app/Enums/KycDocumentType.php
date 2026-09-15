<?php

namespace App\Enums;

enum KycDocumentType: string
{
    case TAX_CERTIFICATE = 'TAX_CERTIFICATE';           // vergi levhası
    case TRADE_REGISTRY_GAZETTE = 'TRADE_REGISTRY_GAZETTE'; // ticaret sicil gazetesi
    case SIGNATURE_CIRCULAR = 'SIGNATURE_CIRCULAR';     // imza sirküleri
    case IDENTITY_DOCUMENT = 'IDENTITY_DOCUMENT';       // kimlik belgesi
    case ACTIVITY_CERTIFICATE = 'ACTIVITY_CERTIFICATE'; // faaliyet belgesi
    case POWER_OF_ATTORNEY = 'POWER_OF_ATTORNEY';       // vekaletname

    /**
     * Şirketin KYC'sinin tamamlanmış sayılması için ZORUNLU belgeler.
     * POWER_OF_ATTORNEY yalnızca vekil başvurularında gerekir, bu yüzden
     * zorunlu listede değil.
     *
     * @return array<self>
     */
    public static function required(): array
    {
        return [
            self::TAX_CERTIFICATE,
            self::TRADE_REGISTRY_GAZETTE,
            self::SIGNATURE_CIRCULAR,
            self::IDENTITY_DOCUMENT,
        ];
    }

    public function isRequired(): bool
    {
        return in_array($this, self::required(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::TAX_CERTIFICATE => 'Vergi levhası',
            self::TRADE_REGISTRY_GAZETTE => 'Ticaret sicil gazetesi',
            self::SIGNATURE_CIRCULAR => 'İmza sirküleri',
            self::IDENTITY_DOCUMENT => 'Kimlik belgesi',
            self::ACTIVITY_CERTIFICATE => 'Faaliyet belgesi',
            self::POWER_OF_ATTORNEY => 'Vekaletname',
        };
    }
}
