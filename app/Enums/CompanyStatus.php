<?php

namespace App\Enums;

/**
 * Şirket aktivasyon state machine — V5 bölüm 11.
 *
 * Durumlar migration'daki enum ile BİREBİR aynı olmalıdır.
 *
 * İzinli geçişler burada tanımlıdır ve tek kaynaktır: bir servis "status"
 * kolonunu doğrudan yazarsa state machine baypas edilmiş olur. Bu yüzden
 * CompanyActivationService dışında hiçbir yer status atamamalıdır.
 */
enum CompanyStatus: string
{
    case REGISTERED = 'REGISTERED';
    case KYC_PENDING = 'KYC_PENDING';
    case KYC_REVIEW = 'KYC_REVIEW';
    case KYC_APPROVED = 'KYC_APPROVED';
    case CONTRACT_PENDING = 'CONTRACT_PENDING';
    case CONTRACT_SIGNED = 'CONTRACT_SIGNED';
    case PAYMENT_PENDING = 'PAYMENT_PENDING';
    case PAYMENT_AUTHORIZED = 'PAYMENT_AUTHORIZED';
    case PAYMENT_CONFIRMED = 'PAYMENT_CONFIRMED';
    case ADDRESS_ASSIGNED = 'ADDRESS_ASSIGNED';
    case ACTIVE = 'ACTIVE';
    case SUSPENDED = 'SUSPENDED';
    case TERMINATION_PENDING = 'TERMINATION_PENDING';
    case TERMINATED = 'TERMINATED';

    /**
     * Bu durumdan gidilebilecek durumlar.
     *
     * Tasarım notu: KYC_REVIEW -> KYC_PENDING geçişi "ek bilgi istendi"
     * (kyc.request_more_info) akışıdır; belge reddedilince müşteri yeniden
     * yükleyebilmelidir. TERMINATED terminaldir: oradan çıkış yoktur, çünkü
     * fesih sonrası yeniden aktivasyon YENİ bir şirket kaydıdır (denetim
     * izinin kopmaması için).
     *
     * @return array<CompanyStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::REGISTERED => [self::KYC_PENDING, self::TERMINATED],
            self::KYC_PENDING => [self::KYC_REVIEW, self::TERMINATED],
            self::KYC_REVIEW => [self::KYC_APPROVED, self::KYC_PENDING, self::TERMINATED],
            self::KYC_APPROVED => [self::CONTRACT_PENDING, self::SUSPENDED, self::TERMINATED],
            self::CONTRACT_PENDING => [self::CONTRACT_SIGNED, self::SUSPENDED, self::TERMINATED],
            self::CONTRACT_SIGNED => [self::PAYMENT_PENDING, self::SUSPENDED, self::TERMINATED],
            self::PAYMENT_PENDING => [self::PAYMENT_AUTHORIZED, self::SUSPENDED, self::TERMINATED],
            self::PAYMENT_AUTHORIZED => [self::PAYMENT_CONFIRMED, self::SUSPENDED, self::TERMINATED],
            self::PAYMENT_CONFIRMED => [self::ADDRESS_ASSIGNED, self::SUSPENDED, self::TERMINATED],
            self::ADDRESS_ASSIGNED => [self::ACTIVE, self::SUSPENDED, self::TERMINATED],
            self::ACTIVE => [self::SUSPENDED, self::TERMINATION_PENDING],
            self::SUSPENDED => [self::ACTIVE, self::TERMINATION_PENDING, self::TERMINATED],
            self::TERMINATION_PENDING => [self::TERMINATED, self::ACTIVE],
            self::TERMINATED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** Şirket normal iş operasyonu yapabilir mi? */
    public function isOperational(): bool
    {
        return $this === self::ACTIVE;
    }

    public function label(): string
    {
        return match ($this) {
            self::REGISTERED => 'Kayıt alındı',
            self::KYC_PENDING => 'KYC bekleniyor',
            self::KYC_REVIEW => 'KYC incelemede',
            self::KYC_APPROVED => 'KYC onaylandı',
            self::CONTRACT_PENDING => 'Sözleşme bekleniyor',
            self::CONTRACT_SIGNED => 'Sözleşme imzalandı',
            self::PAYMENT_PENDING => 'Ödeme bekleniyor',
            self::PAYMENT_AUTHORIZED => 'Ödeme provizyonu alındı',
            self::PAYMENT_CONFIRMED => 'Ödeme onaylandı',
            self::ADDRESS_ASSIGNED => 'Adres tahsis edildi',
            self::ACTIVE => 'Aktif',
            self::SUSPENDED => 'Askıya alındı',
            self::TERMINATION_PENDING => 'Fesih sürecinde',
            self::TERMINATED => 'Feshedildi',
        };
    }
}
