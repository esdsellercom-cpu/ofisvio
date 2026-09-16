<?php

namespace App\Services;

use App\Models\Website;
use Closure;
use Illuminate\Contracts\Cache\Repository;

/**
 * Cache Engine (faz 12) + tenant izolasyonu (faz 13) + temel gözlem (faz 14).
 *
 * ANAHTAR ŞEMASI: site:{website_id}:v{sürüm}:{ad}
 *   - Her website'in kendi SÜRÜM sayacı vardır (site:{id}:v). Geçersizleme
 *     sayacı artırır; eski anahtarlar TTL ile düşer, hiçbir zaman başka bir
 *     site'in anahtarına dokunulmaz. Etiket (tags) gerektirmez: database/file
 *     sürücülerinde de çalışır, Redis'te de.
 *   - Tenant izolasyonu anahtarın kendisindedir: website_id olmadan anahtar
 *     kurulamaz; "hangi tenant'ın verisi" sorusu anahtardan okunur.
 *
 * GÖZLEM: site başına isabet/ıskalama sayaçları (site:{id}:stats:*). Panelde
 * gösterilir; sayaçlar da önbellekte durur, DB'ye yazmaz.
 *
 * GLOBAL PURGE (tüm sürücü) yıkıcıdır: matriste cache.invalidate JIT ister.
 */
class ContentCache
{
    /** Okuma anahtarlarının güvenlik TTL'i: geçersizleme kaçarsa bile en fazla bu kadar bayat kalır. */
    public const TTL_SECONDS = 600;

    /**
     * Saklama BİÇİMİ sürümü. Önbelleğe yazılan yapının şekli değişince (ör. nesne
     * -> ham dizi geçişi) artırılır: eski anahtarlar bir daha okunmaz, TTL ile
     * düşer. Geliştirme DB'sindeki bayat kayıt bir kez 500 üretti — bir daha
     * üretmesin.
     */
    public const SCHEMA = 2;

    public function __construct(private readonly Repository $cache) {}

    /**
     * Önbellekten dönen değerin tipi GARANTİ DEĞİLDİR (eski biçim, bozuk kayıt,
     * unserialize edilemeyen nesne): çağıran doğrulamak zorundadır.
     *
     * @param  Closure(): mixed  $compute
     */
    public function remember(Website $website, string $name, Closure $compute): mixed
    {
        $key = $this->key($website, $name);

        if ($this->cache->has($key)) {
            $this->bump($this->statKey($website, 'hits'));

            return $this->cache->get($key);
        }

        $this->bump($this->statKey($website, 'misses'));
        $value = $compute();
        $this->cache->put($key, $value, self::TTL_SECONDS);

        return $value;
    }

    /**
     * Anahtarı yeniden hesaplayıp ÜZERİNE yazar (bozuk/eski biçimli kayıt için).
     *
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    public function refresh(Website $website, string $name, Closure $compute): mixed
    {
        $value = $compute();
        $this->cache->put($this->key($website, $name), $value, self::TTL_SECONDS);
        $this->bump($this->statKey($website, 'misses'));

        return $value;
    }

    /** Website'in tüm önbelleğini geçersiz kılar — yalnızca o site'i. */
    public function invalidate(Website $website): int
    {
        $versionKey = $this->versionKey($website);
        $next = $this->version($website) + 1;
        $this->cache->forever($versionKey, $next);
        $this->bump($this->statKey($website, 'purges'));

        return $next;
    }

    /** Tüm önbellek sürücüsünü boşaltır. YIKICI: oturum/hız sınırı sayaçları dahil. */
    public function purgeAll(): void
    {
        $this->cache->getStore()->flush();
    }

    public function version(Website $website): int
    {
        return (int) $this->cache->get($this->versionKey($website), 1);
    }

    /** @return array{version: int, hits: int, misses: int, purges: int, hit_ratio: float|null} */
    public function stats(Website $website): array
    {
        $hits = (int) $this->cache->get($this->statKey($website, 'hits'), 0);
        $misses = (int) $this->cache->get($this->statKey($website, 'misses'), 0);

        return [
            'version' => $this->version($website),
            'hits' => $hits,
            'misses' => $misses,
            'purges' => (int) $this->cache->get($this->statKey($website, 'purges'), 0),
            'hit_ratio' => ($hits + $misses) > 0 ? round($hits / ($hits + $misses), 3) : null,
        ];
    }

    public function key(Website $website, string $name): string
    {
        return "site:{$website->id}:s".self::SCHEMA.":v{$this->version($website)}:{$name}";
    }

    /**
     * Sayaç artırma. Database sürücüsünde increment() olmayan anahtarı OLUŞTURMAZ
     * (false döner); önce sıfırla açılır. Sayaçlar istatistiktir, yarış kabul edilir.
     */
    private function bump(string $key): void
    {
        if (! $this->cache->has($key)) {
            $this->cache->forever($key, 0);
        }

        $this->cache->increment($key);
    }

    private function versionKey(Website $website): string
    {
        return "site:{$website->id}:v";
    }

    private function statKey(Website $website, string $metric): string
    {
        return "site:{$website->id}:stats:{$metric}";
    }
}
