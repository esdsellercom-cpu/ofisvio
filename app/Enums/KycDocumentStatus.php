<?php

namespace App\Enums;

enum KycDocumentStatus: string
{
    case PENDING = 'PENDING';               // yüklendi, incelenmedi
    case UNDER_REVIEW = 'UNDER_REVIEW';     // inceleniyor
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case MORE_INFO_REQUIRED = 'MORE_INFO_REQUIRED';
    case SUPERSEDED = 'SUPERSEDED';         // yerine yenisi yüklendi

    /** @return array<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDING => [self::UNDER_REVIEW, self::SUPERSEDED],
            self::UNDER_REVIEW => [self::APPROVED, self::REJECTED, self::MORE_INFO_REQUIRED],
            // Reddedilen/eksik bilgi istenen belge silinmez: yerine yenisi
            // yüklenince SUPERSEDED olur. Denetim izi korunur.
            self::REJECTED, self::MORE_INFO_REQUIRED => [self::SUPERSEDED],
            self::APPROVED => [self::SUPERSEDED],
            self::SUPERSEDED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Bu belge şirketin KYC'sini ilerletmek için sayılır mı? */
    public function countsAsComplete(): bool
    {
        return $this === self::APPROVED;
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'İnceleme bekliyor',
            self::UNDER_REVIEW => 'İnceleniyor',
            self::APPROVED => 'Onaylandı',
            self::REJECTED => 'Reddedildi',
            self::MORE_INFO_REQUIRED => 'Ek bilgi gerekli',
            self::SUPERSEDED => 'Yenisiyle değiştirildi',
        };
    }
}
