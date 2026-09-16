<?php

namespace App\Services;

use App\Models\Website;

/**
 * İstek için geçerli website (faz 10 — çoklu website).
 *
 * Çözümleme sırası:
 *   1. İsteğin Host'u bir website'in `domain` alanıyla eşleşiyorsa o site
 *      (müşteri sitesi — §66 Organization -> Website).
 *   2. Aksi halde varsayılan site (Ofisvio vitrini).
 *
 * Host'a GÜVENİLMEZ: yalnızca veritabanındaki alan adlarıyla eşleştirilir;
 * eşleşmeyen her host varsayılan siteye düşer, hiçbir zaman "yeni site"
 * üretmez. Port ve büyük/küçük harf yok sayılır. İstek boyunca tek örnek
 * (singleton) — her çağrıda yeniden sorgulanmaz.
 */
class CurrentWebsite
{
    private ?Website $resolved = null;

    private bool $resolvedOnce = false;

    private ?string $host = null;

    public function setHost(?string $host): void
    {
        $this->host = $host;
        $this->resolvedOnce = false;
        $this->resolved = null;
    }

    public function get(): ?Website
    {
        if ($this->resolvedOnce) {
            return $this->resolved;
        }

        $this->resolvedOnce = true;
        $host = $this->normalize($this->host);

        if ($host !== null) {
            $byDomain = Website::query()->whereRaw('lower(domain) = ?', [$host])->first();

            if ($byDomain !== null) {
                return $this->resolved = $byDomain;
            }
        }

        return $this->resolved = Website::query()->default()->first();
    }

    /** Varsayılan (Ofisvio) site mi, müşteri sitesi mi? */
    public function isTenantSite(): bool
    {
        $site = $this->get();

        return $site !== null && ! $site->is_default;
    }

    private function normalize(?string $host): ?string
    {
        if ($host === null || $host === '') {
            return null;
        }

        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host; // port

        return $host === '' ? null : $host;
    }
}
