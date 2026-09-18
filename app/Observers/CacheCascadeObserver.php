<?php

namespace App\Observers;

use App\Models\CacheEvent;
use App\Models\Content;
use App\Models\Location;
use App\Models\SeoLandingPage;
use App\Models\Service;
use App\Models\SiteRevision;
use App\Models\Website;
use App\Services\ContentCache;
use Illuminate\Database\Eloquent\Model;

/**
 * Önbellek geçersizleme kaskadı (faz 60f): CMS'de hizmet/lokasyon/içerik/hizmet × şehir/site/yayın değişince ilgili
 * sitenin önbellek SÜRÜMÜ atlar — SEO meta, şema, GEO varlığı, iç bağlantı grafı, ilgili lokasyon sayfaları ve sayfa
 * önbelleği aynı sürüm ad alanında olduğundan hepsi tek adımda düşer; eski SEO/GEO çıktısı asla servis edilmez.
 * Servisler zaten geçersiz kılar; bu gözlemci emniyet ağı + görünür iz (cache_events) sağlar.
 */
class CacheCascadeObserver
{
    /** @var array<string, list<string>> */
    private const STEPS = [
        'service' => ['Hizmet sayfası + liste SEO meta', 'Service / makesOffer şeması', 'GEO varlığı (llms.txt, cevaplar)', 'İç bağlantı grafı', 'İlgili lokasyon ve hizmet × şehir sayfaları', 'Sayfa önbelleği (sürüm atladı)'],
        'location' => ['Lokasyon sayfası + liste SEO meta', 'LocalBusiness şeması', 'GEO varlığı (llms.txt)', 'İç bağlantı grafı', 'Hizmet × şehir sayfaları', 'Sitemap', 'Sayfa önbelleği (sürüm atladı)'],
        'content' => ['İçerik SEO meta', 'Article / WebPage / FAQPage şeması', 'GEO varlığı (ilişkiler)', 'İç bağlantı grafı', 'Sitemap', 'Sayfa önbelleği (sürüm atladı)'],
        'landing' => ['Hizmet × şehir sayfası SEO meta', 'Service + LocalBusiness şeması', 'Sitemap', 'Sayfa önbelleği (sürüm atladı)'],
        'website' => ['Site geneli SEO ayarları', 'Organization / WebSite şeması', 'llms.txt / robots / sitemap', 'Sayfa önbelleği (sürüm atladı)'],
        'revision' => ['Ana sayfa bölümleri', 'Ana sayfa şeması', 'Sayfa önbelleği (sürüm atladı)'],
    ];

    public function __construct(private readonly ContentCache $cache) {}

    public function saved(Model $model): void
    {
        $this->cascade($model, 'saved');
    }

    public function deleted(Model $model): void
    {
        $this->cascade($model, 'deleted');
    }

    private function cascade(Model $model, string $event): void
    {
        [$type, $website] = match (true) {
            $model instanceof Service => ['service', Website::query()->default()->first()],
            $model instanceof Location => ['location', Website::query()->default()->first()],
            $model instanceof Content => ['content', $model->website],
            $model instanceof SeoLandingPage => ['landing', $model->website],
            $model instanceof Website => ['website', $model],
            $model instanceof SiteRevision => ['revision', Website::query()->find($model->website_id)],
            default => [null, null],
        };

        if ($type === null || $website === null) {
            return;
        }

        $version = $this->cache->invalidate($website);
        CacheEvent::query()->create([
            'website_id' => $website->id,
            'trigger' => $type.'.'.$event,
            'entity_type' => $type,
            'entity_id' => (int) $model->getKey(),
            'steps' => self::STEPS[$type],
            'version_after' => $version,
            'created_at' => now(),
        ]);
    }
}
