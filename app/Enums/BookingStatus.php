<?php

namespace App\Enums;

/**
 * Rezervasyon durum makinesi (master prompt §7). Geçişler TEK kaynak burada;
 * BookingService::transition dışında status yazılmaz, frontend durum değiştiremez.
 *
 *   REQUESTED → PENDING_APPROVAL (onay politikası) | CONFIRMED (otomatik onay)
 *   PENDING_APPROVAL → CONFIRMED | REJECTED | CANCELLED | EXPIRED
 *   CONFIRMED → CHECKED_IN | COMPLETED | CANCELLED | NO_SHOW
 *   CHECKED_IN → COMPLETED
 *
 * Odayı meşgul eden durumlar (uygunluk motoru "pending hold" dahil): blocksRoom().
 */
enum BookingStatus: string
{
    case REQUESTED = 'REQUESTED';
    case PENDING_APPROVAL = 'PENDING_APPROVAL';
    case CONFIRMED = 'CONFIRMED';
    case CHECKED_IN = 'CHECKED_IN';
    case COMPLETED = 'COMPLETED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';
    case EXPIRED = 'EXPIRED';
    case NO_SHOW = 'NO_SHOW';

    /** @return array<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::REQUESTED => [self::PENDING_APPROVAL, self::CONFIRMED, self::REJECTED, self::CANCELLED, self::EXPIRED],
            self::PENDING_APPROVAL => [self::CONFIRMED, self::REJECTED, self::CANCELLED, self::EXPIRED],
            self::CONFIRMED => [self::CHECKED_IN, self::COMPLETED, self::CANCELLED, self::NO_SHOW],
            self::CHECKED_IN => [self::COMPLETED],
            self::COMPLETED, self::REJECTED, self::CANCELLED, self::EXPIRED, self::NO_SHOW => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function blocksRoom(): bool
    {
        return in_array($this, [self::REQUESTED, self::PENDING_APPROVAL, self::CONFIRMED, self::CHECKED_IN], true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** @return array<string> */
    public static function blockingValues(): array
    {
        return array_map(fn (self $s) => $s->value, array_filter(self::cases(), fn (self $s) => $s->blocksRoom()));
    }

    public function label(): string
    {
        return match ($this) {
            self::REQUESTED => 'Talep alındı',
            self::PENDING_APPROVAL => 'Onay bekliyor',
            self::CONFIRMED => 'Onaylı',
            self::CHECKED_IN => 'Giriş yapıldı',
            self::COMPLETED => 'Tamamlandı',
            self::REJECTED => 'Reddedildi',
            self::CANCELLED => 'İptal',
            self::EXPIRED => 'Süresi doldu',
            self::NO_SHOW => 'Gelmedi',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::CONFIRMED, self::CHECKED_IN, self::COMPLETED => 'ok',
            self::REQUESTED, self::PENDING_APPROVAL => 'warn',
            self::REJECTED, self::CANCELLED, self::EXPIRED, self::NO_SHOW => 'muted',
        };
    }
}
