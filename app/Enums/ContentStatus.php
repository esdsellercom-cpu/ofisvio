<?php

namespace App\Enums;

/**
 * İçerik yayın akışı — matris notu: "draft-review-approved-published akisi".
 *
 * İzinli geçişler TEK kaynaktır; ContentService dışında status yazılmaz.
 * Onay (APPROVED) yalnızca requires_approval işaretli içerik için zorunludur
 * (yasal/vergi/KYC metinleri — "P0B"); diğerleri IN_REVIEW'dan doğrudan
 * yayınlanabilir. Bu ayrım enum'da değil ContentService::transition'da
 * uygulanır; enum yalnızca "hangi geçiş mümkün" sorusunu cevaplar.
 */
enum ContentStatus: string
{
    case DRAFT = 'DRAFT';
    case IN_REVIEW = 'IN_REVIEW';
    case APPROVED = 'APPROVED';
    case SCHEDULED = 'SCHEDULED';
    case PUBLISHED = 'PUBLISHED';
    case ARCHIVED = 'ARCHIVED';

    /** @return array<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::DRAFT => [self::IN_REVIEW, self::ARCHIVED],
            self::IN_REVIEW => [self::DRAFT, self::APPROVED, self::SCHEDULED, self::PUBLISHED],
            self::APPROVED => [self::DRAFT, self::SCHEDULED, self::PUBLISHED],
            self::SCHEDULED => [self::PUBLISHED, self::DRAFT],
            self::PUBLISHED => [self::ARCHIVED, self::DRAFT],
            // Arşiv terminal değildir: metin taslağa dönüp yeniden yayınlanabilir.
            self::ARCHIVED => [self::DRAFT],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Sitede görünür mü? */
    public function isLive(): bool
    {
        return $this === self::PUBLISHED;
    }

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Taslak',
            self::IN_REVIEW => 'İncelemede',
            self::APPROVED => 'Onaylandı',
            self::SCHEDULED => 'Zamanlandı',
            self::PUBLISHED => 'Yayında',
            self::ARCHIVED => 'Arşiv',
        };
    }
}
