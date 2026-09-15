<?php

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Tenant context ihlalleri. Kasıtlı olarak 404 DEĞİL 403 döner değil —
 * aşağıdaki notu okuyun:
 *
 * Başka bir organizasyona ait bir kaydın VARLIĞINI sızdırmamak için
 * "yanlış tenant" durumu 404 olarak dışa vurulur (enumeration savunması).
 * "Aktif context yok" durumu ise 409'dur: istemci context seçmelidir.
 */
class TenantContextException extends RuntimeException implements HttpExceptionInterface
{
    private function __construct(string $message, private readonly int $status)
    {
        parent::__construct($message);
    }

    /** Kullanıcı henüz aktif organizasyon seçmemiş. */
    public static function noActiveContext(): self
    {
        return new self('Aktif organizasyon context\'i yok.', 409);
    }

    /** Kullanıcı bu organizasyonun üyesi değil (veya üyeliği askıya alınmış). */
    public static function notAMember(int $organizationId): self
    {
        return new self("Organizasyon {$organizationId} için geçerli üyelik yok.", 403);
    }

    /**
     * İstenen kayıt aktif organizasyona ait değil. 404 döneriz: 403 demek
     * "bu kayıt var ama senin değil" bilgisini sızdırır.
     */
    public static function outsideActiveTenant(): self
    {
        return new self('Kayıt bulunamadı.', 404);
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getHeaders(): array
    {
        return [];
    }
}
