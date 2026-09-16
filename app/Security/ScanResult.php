<?php

namespace App\Security;

/**
 * Zararlı yazılım taramasının sonucu. Üç hal vardır ve üçü de ayrı ele alınır:
 * temiz -> kabul; enfekte -> karantina; tarama yapılamadı -> RED (fail-closed).
 * "Yapılamadı"yı "temiz" saymak, tarayıcı çöktüğünde kapıyı açmak olurdu.
 */
final class ScanResult
{
    private function __construct(
        public readonly bool $clean,
        public readonly bool $available,
        public readonly ?string $signature,
    ) {}

    public static function clean(): self
    {
        return new self(true, true, null);
    }

    public static function infected(string $signature): self
    {
        return new self(false, true, $signature);
    }

    public static function unavailable(string $reason): self
    {
        return new self(false, false, $reason);
    }

    public function isInfected(): bool
    {
        return $this->available && ! $this->clean;
    }
}
