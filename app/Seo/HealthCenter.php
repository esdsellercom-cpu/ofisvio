<?php

namespace App\Seo;

use App\Integrations\SecretStore;
use App\Models\Content;
use App\Models\EntityRelation;
use App\Models\SeoIssue;
use App\Models\SeoLandingPage;
use App\Models\User;
use App\Models\Website;
use App\Services\AuditService;
use App\Services\ContentCache;
use App\Services\ContentService;
use App\Services\GeoService;
use App\Services\LandingPageService;
use App\Services\RedirectService;
use App\Services\SeoService;
use App\Services\SeoSettingsService;
use App\Services\ServiceService;
use DomainException;
use Illuminate\Support\Facades\File;

/**
 * SEO & GEO Command Center — sağlık merkezi (faz 60). Tek ekranda 16 kategori; her bulgu gerçek veriden
 * (içerik, hizmet, lokasyon, ayar, yönlendirme tablosu, şema çıktısı, performans baseline'ı, entegrasyon
 * durumu) her açılışta yeniden hesaplanır. Dış servis çağrısı yok; bağlı olmayan entegrasyon "bağlı değil"
 * bilgisidir, asla sahte metrik değildir.
 *
 * Bulgu: kategori, önem, adres, neden, öneri, otomatik düzeltme (varsa) — her düzeltme onay ister ve kendi
 * yetkisiyle (edit / critical+JIT) uygulanır. Yönetici kararı (yok say / çözüldü / not) `seo_issues`'ta.
 */
class HealthCenter
{
    public const CATEGORIES = [
        'technical' => 'Teknik SEO',
        'indexability' => 'İndekslenebilirlik',
        'sitemap' => 'Sitemap',
        'robots' => 'Robots',
        'canonical' => 'Canonical',
        'schema' => 'Schema',
        'performance' => 'Performans',
        'content' => 'İçerik',
        'internal_links' => 'İç bağlantılar',
        'broken_links' => 'Kırık bağlantılar',
        'duplicate_content' => 'Çift içerik',
        'thin_content' => 'Zayıf içerik',
        'geo_coverage' => 'GEO kapsamı',
        'entity_health' => 'Varlık sağlığı',
        'search_performance' => 'Arama performansı',
        'ai_content' => 'AI içerik',
    ];

    public const SEVERITIES = ['critical' => 'Kritik', 'high' => 'Yüksek', 'medium' => 'Orta', 'low' => 'Düşük', 'info' => 'Bilgi'];

    private const WEIGHTS = ['critical' => 15, 'high' => 8, 'medium' => 4, 'low' => 1, 'info' => 0];

    /**
     * Otomatik düzeltmeler: her biri onay ister; mode edit → seo.edit, critical → seo.settings + JIT (rotada).
     *
     * @var array<string, array{label: string, mode: string}>
     */
    public const FIXES = [
        'canonical_auto' => ['label' => 'Otomatik canonical\'ı aç', 'mode' => 'critical'],
        'sitemap_enable' => ['label' => 'Sitemap\'i aç', 'mode' => 'critical'],
        'schema_enable' => ['label' => 'JSON-LD üretimini aç', 'mode' => 'edit'],
        'noindex_content' => ['label' => 'Sayfayı noindex yap', 'mode' => 'edit'],
    ];

    /** Zayıf içerik eşiği (karakter) ve yakın-kopya eşiği (Jaccard). */
    public const THIN_CHARS = 300;

    public const DUPLICATE_JACCARD = 0.6;

    public function __construct(
        private readonly SeoService $seo,
        private readonly SeoSettingsService $settings,
        private readonly GeoService $geo,
        private readonly ServiceService $services,
        private readonly ContentService $contents,
        private readonly SchemaInspector $inspector,
        private readonly RedirectService $redirects,
        private readonly SecretStore $secrets,
        private readonly AuditService $audit,
        private readonly ContentCache $cache,
        private readonly LandingPageService $landing,
    ) {}

    /**
     * @return array{
     *   issues: list<array<string, mixed>>,
     *   categories: array<string, array{label: string, open: int, worst: string|null, ignored: int}>,
     *   summary: array{open: int, ignored: int, resolved: int, score: int, autofixable: int},
     *   integrations: array<string, array{connected: bool, note: string}>
     * }
     */
    public function report(Website $website): array
    {
        $issues = $this->detect($website);
        $decisions = SeoIssue::query()->where('website_id', $website->id)->get()->keyBy('issue_key');
        $categories = [];

        foreach (self::CATEGORIES as $key => $label) {
            $categories[$key] = ['label' => $label, 'open' => 0, 'worst' => null, 'ignored' => 0];
        }

        $summary = ['open' => 0, 'ignored' => 0, 'resolved' => 0, 'score' => 100, 'autofixable' => 0];
        $penalty = 0;
        $order = array_keys(self::SEVERITIES);

        foreach ($issues as &$issue) {
            $decision = $decisions->get($issue['key']);
            $status = $decision !== null ? $decision->status : SeoIssue::STATUS_OPEN;
            // Çözüldü denmiş ama bulgu hâlâ üretiliyorsa yeniden açık sayılır (karar notu korunur).
            $issue['reopened'] = $status === SeoIssue::STATUS_RESOLVED;
            $issue['status'] = $status === SeoIssue::STATUS_RESOLVED ? SeoIssue::STATUS_OPEN : $status;
            $issue['note'] = $decision?->note;
            $issue['approval'] = true;

            if ($issue['status'] === SeoIssue::STATUS_IGNORED) {
                $categories[$issue['category']]['ignored']++;
                $summary['ignored']++;

                continue;
            }

            $summary['open']++;
            $categories[$issue['category']]['open']++;
            $penalty += self::WEIGHTS[$issue['severity']];

            if ($issue['fix'] !== null) {
                $summary['autofixable']++;
            }

            $worst = $categories[$issue['category']]['worst'];

            if ($worst === null || array_search($issue['severity'], $order, true) < array_search($worst, $order, true)) {
                $categories[$issue['category']]['worst'] = $issue['severity'];
            }
        }
        unset($issue);

        $summary['resolved'] = $decisions->where('status', SeoIssue::STATUS_RESOLVED)->count();
        $summary['score'] = max(0, 100 - $penalty);

        usort($issues, fn (array $a, array $b) => [array_search($a['severity'], $order, true), $a['category']] <=> [array_search($b['severity'], $order, true), $b['category']]);

        return ['issues' => $issues, 'categories' => $categories, 'summary' => $summary, 'integrations' => $this->integrations()];
    }

    /** Yönetici kararı: yok say / çözüldü / yeniden aç (+ not). Audit. */
    public function decide(User $actor, Website $website, string $key, string $status, ?string $note = null): SeoIssue
    {
        if (! in_array($status, SeoIssue::STATUSES, true)) {
            throw new DomainException('Geçersiz durum.');
        }

        $issue = $this->find($website, $key);

        if ($issue === null) {
            throw new DomainException('Bulgu artık üretilmiyor (çözülmüş olabilir); sayfayı yenileyin.');
        }

        $row = SeoIssue::query()->updateOrCreate(
            ['website_id' => $website->id, 'issue_key' => $key],
            ['category' => $issue['category'], 'status' => $status, 'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null, 'decided_by' => $actor->id, 'decided_at' => now(), 'last_seen_at' => now()],
        );
        $this->audit->record($actor, 'seo.issue_decided', 'website', $website->id, [], ['key' => $key, 'title' => $issue['title'], 'status' => $status]);

        return $row;
    }

    /**
     * Otomatik düzeltme uygulama (onaydan sonra). Düzeltmenin modu rota yetkisiyle eşleşmeli; çağıran
     * controller yalnız kendi rotasının moduna izin verir.
     */
    public function autofix(User $actor, Website $website, string $key, string $allowedMode): string
    {
        $issue = $this->find($website, $key);

        if ($issue === null || $issue['fix'] === null) {
            throw new DomainException('Bu bulgu için otomatik düzeltme yok ya da bulgu artık üretilmiyor.');
        }

        [$fix, $param] = array_pad(explode(':', (string) $issue['fix'], 2), 2, null);
        $definition = self::FIXES[$fix] ?? null;

        if ($definition === null || $definition['mode'] !== $allowedMode) {
            throw new DomainException('Bu düzeltme farklı bir yetki kapısından uygulanır.');
        }

        switch ($fix) {
            case 'canonical_auto':
                $this->settings->set($actor, $website, ['url.canonical_auto' => true], 'Command Center otomatik düzeltme');
                break;
            case 'sitemap_enable':
                $this->settings->set($actor, $website, ['crawl.sitemap_enabled' => true], 'Command Center otomatik düzeltme');
                break;
            case 'schema_enable':
                $this->settings->set($actor, $website, ['schema.enabled' => true], 'Command Center otomatik düzeltme');
                break;
            case 'noindex_content':
                $content = Content::query()->where('website_id', $website->id)->find((int) $param);

                if ($content === null) {
                    throw new DomainException('İçerik bulunamadı.');
                }

                $content->forceFill(['noindex' => true])->save();
                $this->cache->invalidate($website);
                $this->audit->record($actor, 'content.noindex_set', 'content', $content->id, ['noindex' => false], ['noindex' => true, 'reason' => 'Command Center']);
                break;
        }

        SeoIssue::query()->updateOrCreate(
            ['website_id' => $website->id, 'issue_key' => $key],
            ['category' => $issue['category'], 'status' => SeoIssue::STATUS_RESOLVED, 'decided_by' => $actor->id, 'decided_at' => now(), 'last_seen_at' => now(), 'note' => 'Otomatik düzeltme: '.$definition['label']],
        );
        $this->audit->record($actor, 'seo.autofix_applied', 'website', $website->id, [], ['key' => $key, 'fix' => $fix, 'title' => $issue['title']]);

        return $definition['label'];
    }

    /** @return array<string, mixed>|null */
    public function find(Website $website, string $key): ?array
    {
        foreach ($this->detect($website) as $issue) {
            if ($issue['key'] === $key) {
                return $issue;
            }
        }

        return null;
    }

    /**
     * Entegrasyon bağlantı durumu (env): gerçek veri yoksa ekranda yalnız bu durum gösterilir.
     *
     * @return array<string, array{connected: bool, note: string}>
     */
    public function integrations(): array
    {
        $out = [];

        foreach (['search_console' => 'Google Search Console', 'analytics' => 'Analytics', 'ai' => 'AI sağlayıcısı', 'pagespeed' => 'PageSpeed Insights'] as $provider => $label) {
            $known = is_array(config('integrations.providers.'.$provider));
            $enabled = $known && $this->secrets->enabled($provider);
            $missing = $known ? $this->secrets->missing($provider) : [];
            $out[$provider] = [
                'connected' => $enabled && $missing === [],
                'note' => ! $known ? $label.': tanımlı değil' : (! $enabled ? $label.': kapalı (env ile açılır)' : ($missing !== [] ? $label.': eksik secret — '.implode(', ', $missing) : $label.': bağlı')),
            ];
        }

        return $out;
    }

    // ---- Tespit -------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function detect(Website $website): array
    {
        $issues = [];
        $s = $this->settings->for($website);
        $live = $this->contents->livePages($website)->merge($this->contents->livePosts($website, 1000));
        $add = function (string $category, string $id, string $severity, string $title, ?string $url, string $cause, string $recommendation, ?string $fix = null) use (&$issues): void {
            $issues[] = [
                'key' => sha1($category.'|'.$id),
                'category' => $category,
                'severity' => $severity,
                'title' => $title,
                'url' => $url,
                'cause' => $cause,
                'recommendation' => $recommendation,
                'fix' => $fix,
                'fix_label' => $fix !== null ? (self::FIXES[explode(':', $fix, 2)[0]]['label'] ?? null) : null,
                'fix_mode' => $fix !== null ? (self::FIXES[explode(':', $fix, 2)[0]]['mode'] ?? null) : null,
            ];
        };

        // İndekslenebilirlik
        if (! $website->robots_index) {
            $add('indexability', 'robots_index', 'critical', 'Tüm site noindex', '/', 'Yalın ayardaki "indekslensin" bayrağı kapalı.', 'Yayına hazır olduğunda SEO ayarlarından indekslemeyi açın (JIT).');
        }

        foreach ($live as $content) {
            if ($content->noindex) {
                $add('indexability', 'noindex:'.$content->id, 'info', 'noindex sayfa: '.$content->title, $content->path(), 'İçerik noindex işaretli.', 'Bilinçli değilse içerik formundan kaldırın.');
            }
        }

        if ($s['crawl.noindex_listings']) {
            $add('indexability', 'noindex_listings', 'info', 'Kategori/etiket sayfaları noindex', '/blog', 'Tarama ayarı liste sayfalarını indekse kapatıyor.', 'Kategori sayfaları uzun kuyruk trafiği alıyorsa açın.');
        }

        if (trim((string) $s['security.x_robots_tag']) !== '' && str_contains((string) $s['security.x_robots_tag'], 'noindex')) {
            $add('indexability', 'x_robots', 'critical', 'X-Robots-Tag noindex', '/', 'Güvenlik sekmesindeki X-Robots-Tag başlığı noindex taşıyor; sayfa içi meta ne derse desin indeks kapanır.', 'Başlığı kaldırın (JIT).');
        }

        // Sitemap
        if (! $s['crawl.sitemap_enabled']) {
            $add('sitemap', 'disabled', 'high', 'sitemap.xml kapalı (404)', '/sitemap.xml', 'Tarama ayarı sitemap\'i kapatıyor.', 'Sitemap\'i açın; arama motorları yeni sayfaları buradan keşfeder.', 'sitemap_enable');
        } else {
            $entries = $this->seo->sitemapEntries($website);

            if ($entries === [] && $website->robots_index) {
                $add('sitemap', 'empty', 'high', 'Sitemap boş', '/sitemap.xml', 'Yayında içerik yok ya da tüm türler dışarıda.', 'Yayına içerik alın; sitemap türlerini kontrol edin.');
            }

            $excluded = count((array) $s['crawl.sitemap_exclude']);

            if ($excluded > 0) {
                $add('sitemap', 'excluded', 'info', $excluded.' yol sitemap dışında', '/sitemap.xml', 'Hariç tutulan yol kalıpları tanımlı.', 'Listeyi gözden geçirin; yanlışlıkla önemli sayfa dışarıda kalmasın.');
            }
        }

        // Robots
        if ($s['crawl.robots_mode'] === 'custom' && ! str_contains((string) $s['crawl.robots_custom'], 'Sitemap:')) {
            $add('robots', 'custom_no_sitemap', 'medium', 'Özel robots.txt Sitemap satırı taşımıyor', '/robots.txt', 'Özel metin girilmiş, Sitemap: satırı yok.', 'Sitemap: <adres>/sitemap.xml satırını ekleyin.');
        }

        if (! $s['crawl.ai_crawlers_allowed']) {
            $add('robots', 'ai_blocked', 'info', 'AI tarayıcıları engelli', '/robots.txt', 'GPTBot, ClaudeBot vb. robots.txt ile kapatılmış.', 'GEO hedefi varsa erişimi açın; kapalıyken üretken arama motorları siteyi okuyamaz.');
        }

        // Canonical & öz denetim: her bilinen sayfanın canonical'ı kendini göstermeli.
        if (! $s['url.canonical_auto']) {
            $add('canonical', 'auto_off', 'high', 'Otomatik canonical kapalı', '/', 'Sayfalar canonical etiketi basmıyor.', 'Çift içerik riskine karşı açın.', 'canonical_auto');
        }

        $canonicalBase = $this->seo->canonicalBase($website);
        $pages = $this->inspector->pages($website);
        $heads = [];

        foreach ($pages as $page) {
            $head = $this->inspector->headFor($website, $page['path']);

            if ($head === null) {
                continue;
            }

            $heads[$page['path']] = $head;
            $canonical = (string) ($head['canonical'] ?? '');

            if ($s['url.canonical_auto'] && $canonical !== '' && $canonical !== $canonicalBase.$page['path'] && ! ($page['path'] === '/' && rtrim($canonical, '/') === $canonicalBase)) {
                $add('canonical', 'self:'.$page['path'], 'high', 'Canonical başka sayfayı gösteriyor: '.$page['label'], $page['path'], 'Sayfanın canonical\'ı '.$canonical.' — kendi adresi değil; arama motoru bu sayfayı kopya sayar.', 'İçerikteki canonical alanını temizleyin ya da kasıtlıysa yok sayın.');
            }
        }

        foreach ($this->seo->technicalReport($website) as $group => $block) {
            $category = match ($group) {
                'duplicate_title', 'duplicate_description' => 'duplicate_content',
                'missing_description', 'images_without_alt' => 'content',
                'broken_links' => 'broken_links',
                'orphan_pages' => 'internal_links',
                'canonical' => 'canonical',
                default => 'technical',
            };
            $severity = match ($group) {
                'broken_links', 'redirect_chains', 'mixed_content' => 'high',
                'duplicate_title', 'canonical', 'orphan_pages' => 'medium',
                'missing_description', 'duplicate_description' => 'medium',
                default => 'low',
            };

            foreach ($block['items'] as $item) {
                $add($category, $group.':'.$item, $severity, $block['label'].': '.mb_substr($item, 0, 120), self::pathIn($item), self::causeFor($group), self::recommendationFor($group));
            }
        }

        // Şema
        if (! $s['schema.enabled']) {
            $add('schema', 'disabled', 'medium', 'JSON-LD üretimi kapalı', '/', 'Schema.org sekmesinde üretim kapalı.', 'Açın; Organization/LocalBusiness/Article şemaları zengin sonuç ve GEO için gerekli.', 'schema_enable');
        } else {
            foreach ($heads as $path => $head) {
                $validation = $this->inspector->inspect($website, $path)['validation'] ?? ['errors' => [], 'warnings' => []];

                foreach ($validation['errors'] as $error) {
                    $add('schema', 'error:'.$path.':'.$error, 'medium', 'Şema hatası: '.$error, $path, 'Sayfanın JSON-LD çıktısı schema.org zorunlu alanını taşımıyor.', 'Kaynak veriyi (içerik/lokasyon/hizmet alanları) doldurun; özel JSON-LD ise düzeltin.');
                }
            }
        }

        // Performans (baseline artefaktı)
        $baselinePath = storage_path('app/perf/baseline.json');
        $baseline = File::exists($baselinePath) ? json_decode((string) File::get($baselinePath), true) : null;

        if (! is_array($baseline)) {
            $add('performance', 'no_baseline', 'info', 'Performans baseline ölçümü yok', null, 'ofisvio:perf-baseline çalıştırılmamış.', 'Performans ekranından ölçün (üretim dışı) ya da CI artefaktını kullanın.');
        } else {
            foreach ((array) ($baseline['pages'] ?? []) as $page) {
                if (! is_array($page)) {
                    continue;
                }

                if ((int) ($page['queries'] ?? 0) > 30) {
                    $add('performance', 'queries:'.$page['path'], 'medium', 'Sorgu sayısı yüksek: '.$page['label'].' ('.$page['queries'].')', (string) $page['path'], 'Sayfa 30\'dan fazla sorgu yapıyor.', 'N+1 ve önbellek kullanımını gözden geçirin (QueryBudgetTest bütçesi).');
                }

                if ((float) ($page['ms_median'] ?? 0) > 500) {
                    $add('performance', 'slow:'.$page['path'], 'medium', 'Yavaş yanıt: '.$page['label'].' ('.$page['ms_median'].' ms)', (string) $page['path'], 'Medyan yanıt süresi 500 ms üstü.', 'Önbellek/sorgu optimizasyonu; Performans ekranında yavaş sorgulara bakın.');
                }
            }
        }

        // İçerik / zayıf içerik / çift içerik
        foreach ($this->seo->audit($website) as $finding) {
            $content = $finding['content'];

            foreach ($finding['issues'] as $issue) {
                $thin = str_contains($issue, 'kısa.') && str_contains($issue, 'Gövde');
                $category = $thin ? 'thin_content' : ($issue === 'noindex işaretli — arama motorlarında görünmez.' ? null : 'content');

                if ($category === null) {
                    continue; // noindex zaten indekslenebilirlikte
                }

                $add($category, $content->id.':'.$issue, $thin ? 'medium' : 'low', $content->title.': '.$issue, $content->path(), $thin ? 'Gövde '.self::THIN_CHARS.' karakterden kısa; arama motoru zayıf içerik sayar.' : 'İçerik denetimi kuralı.', $thin ? 'İçeriği genişletin ya da noindex yapın.' : 'İçerik formundan düzeltin.', $thin ? 'noindex_content:'.$content->id : null);
            }
        }

        foreach ($this->nearDuplicates($live->all()) as [$a, $b, $ratio]) {
            $add('duplicate_content', 'near:'.$a->id.':'.$b->id, 'high', 'Yakın kopya gövde: '.$a->title.' ↔ '.$b->title.' (%'.(int) round($ratio * 100).')', $a->path(), 'İki sayfanın gövdesi büyük ölçüde aynı (5 kelimelik parça benzerliği).', 'Birini canonical ile diğerine bağlayın, birleştirin ya da benzersiz içerik yazın.');
        }

        // İç bağlantı: giden bağlantısı hiç olmayan yayın sayfaları
        foreach ($live as $content) {
            if (preg_match('/\]\(/', (string) $content->body) !== 1) {
                $add('internal_links', 'no_outbound:'.$content->id, 'low', 'Giden iç bağlantı yok: '.$content->title, $content->path(), 'Gövdede hiç bağlantı yok.', 'İlgili hizmet/lokasyon/yazıya en az 2 bağlantı ekleyin (İç bağlantı ekranı öneri verir).');
            }
        }

        // GEO kapsamı
        if ($website->is_default) {
            if (trim((string) $s['geo.brand_definition']) === '') {
                $add('geo_coverage', 'brand_definition', 'medium', 'Marka tanımı boş', null, 'GEO sekmesinde marka tanımı girilmemiş; llms.txt ve Organization description boş.', 'Kısa, gerçek bir marka tanımı yazın.');
            }

            if (! $s['geo.llms_enabled']) {
                $add('geo_coverage', 'llms_off', 'low', 'llms.txt kapalı', '/llms.txt', 'Üretken arama motorları için özet dosyası üretilmiyor.', 'GEO sekmesinden açın.');
            }

            if (count((array) $s['geo.faq']) < 2) {
                $add('geo_coverage', 'faq', 'low', 'GEO SSS 2 sorudan az', '/', 'Ana sayfa FAQPage şeması üretilmiyor.', 'GEO sekmesine gerçek soru-cevap ekleyin.');
            }

            foreach ($this->services->active($website) as $service) {
                $answers = is_array($service->answers ?? null) ? $service->answers : [];
                $filled = count(array_filter($answers, fn ($v) => is_string($v) ? trim($v) !== '' : $v !== []));

                if ($filled === 0) {
                    $add('geo_coverage', 'answers:'.$service->id, 'medium', 'Yapılandırılmış cevap yok: '.$service->name, $service->path(), 'Hizmetin "Nedir / Kimler için / Nasıl çalışır / Nerede…" alanları boş.', 'Hizmet formundaki GEO cevaplarını doldurun; AI motorları bu alanlardan alıntı yapar.');
                } elseif ($filled < 6) {
                    $add('geo_coverage', 'answers_partial:'.$service->id, 'low', 'Cevaplar eksik: '.$service->name.' ('.$filled.'/13)', $service->path(), 'Cevap alanlarının yarısından azı dolu.', 'Kalan alanları doldurun.');
                }

                if (trim((string) $service->summary) === '') {
                    $add('thin_content', 'service_summary:'.$service->id, 'medium', 'Hizmet özeti boş: '.$service->name, $service->path(), 'Meta açıklama ve Service şeması özetten beslenir.', 'Hizmet formuna 1–2 cümlelik özet yazın.');
                }
            }
        }

        // Programatik sayfalar (faz 60b): kalite kapısını geçemeyen taslaklar; Knowledge Graph boşlukları.
        if ($website->is_default) {
            foreach ($this->landing->all($website) as $landing) {
                $quality = is_array($landing->quality) ? $landing->quality : null;

                if ($quality !== null && ! ($quality['ok'] ?? false)) {
                    $add($landing->status === SeoLandingPage::STATUS_PUBLISHED ? 'duplicate_content' : 'thin_content', 'landing:'.$landing->id, 'low', 'Programatik sayfa yayın kapısını geçmiyor: '.$landing->title, $landing->path(), implode(' · ', (array) ($quality['issues'] ?? [])), 'Benzersiz, şehre özgü giriş metni yazın; hizmet açıklamasını kopyalamayın.');
                }
            }

            $linked = EntityRelation::query()->where('website_id', $website->id)->where('from_type', 'content')->pluck('from_id')->map(fn ($v) => (int) $v)->all();

            foreach ($this->contents->livePosts($website, 1000) as $post) {
                if (! in_array($post->id, $linked, true)) {
                    $add('internal_links', 'unlinked:'.$post->id, 'low', 'Yazı hiçbir hizmet/lokasyona bağlı değil: '.$post->title, $post->path(), 'Knowledge Graph\'ta Article → Service/Location ilişkisi yok; hizmet sayfası bu yazıyı listelemiyor.', 'Entity ekranından yazıyı ilgili hizmet ve şubeye bağlayın.');
                }
            }
        }

        // Varlık sağlığı
        $brand = $website->brand();

        foreach (['legal_name' => 'Tüzel ad', 'phone' => 'Telefon', 'email' => 'E-posta', 'address' => 'Adres'] as $field => $label) {
            if (trim((string) $brand[$field]) === '') {
                $add('entity_health', 'brand:'.$field, 'low', 'Organization: '.$label.' yok', '/', 'Site marka ayarında '.$label.' boş; Organization şemasına girmiyor.', 'Websiteler ekranından doldurun.');
            }
        }

        if (array_filter((array) ($website->same_as ?? [])) === []) {
            $add('entity_health', 'same_as', 'low', 'Organization: sameAs (sosyal profil) yok', '/', 'Varlık bağlantıları boş; Knowledge Graph eşleşmesi zayıflar.', 'GEO ekranından sosyal profil/Wikidata adreslerini girin.');
        }

        if (trim((string) $s['entity.logo']) === '') {
            $add('entity_health', 'logo', 'low', 'Organization: logo yok', '/', 'Entity sekmesinde logo adresi boş.', 'Kare/yatay logo adresi girin.');
        }

        if ($website->is_default) {
            foreach ($this->geo->audit() as $finding) {
                foreach ($finding['issues'] as $issue) {
                    $add('entity_health', 'location:'.$finding['location']->id.':'.$issue, 'medium', $finding['location']->name.': '.$issue, $finding['location']->path(), 'LocalBusiness şeması ve yerel arama bu alanı ister.', 'Lokasyon künyesinden doldurun.');
                }
            }

            if ($this->geo->publishedLocations()->isEmpty()) {
                $add('geo_coverage', 'no_location', 'high', 'Yayında şube yok', '/lokasyonlar', 'LocalBusiness şeması ve yerel sayfalar üretilmiyor.', 'Lokasyonlar ekranından şubeyi yayına alın.');
            }
        }

        // Yönlendirme / 404
        $stats = $this->redirects->stats($website);

        if ($stats['pending'] > 0) {
            $add('technical', 'redirect_pending', 'medium', $stats['pending'].' yönlendirme önerisi onay bekliyor', null, '404 karar zinciri benzerlik önerisi üretti.', 'Yönlendirmeler & 404 merkezinden onaylayın ya da reddedin.');
        }

        if ($stats['loops'] > 0) {
            $add('technical', 'redirect_loops', 'high', $stats['loops'].' yönlendirme döngüsü', null, 'Yönlendirme tablosunda döngü var.', 'Yönlendirmeler ekranından düzeltin.');
        }

        if ($stats['chains'] > 0) {
            $add('technical', 'redirect_chains_db', 'medium', $stats['chains'].' yönlendirme zinciri', null, 'Bir yönlendirme başka yönlendirmeye gidiyor.', 'Düzleştir ile son hedefe bağlayın.');
        }

        if ($stats['open_404'] > 20) {
            $add('technical', 'open_404', 'medium', $stats['open_404'].' açık 404 kaydı', null, 'Ziyaretçi/bot bulunmayan adreslere geliyor.', 'Sık görülenleri yönlendirin, gürültüyü yok sayın.');
        }

        // Entegrasyonlar: bağlı değilse bilgi; sahte metrik üretilmez.
        $integrations = $this->integrations();

        if (! $integrations['search_console']['connected']) {
            $add('search_performance', 'not_connected', 'info', 'Search Console bağlı değil', null, $integrations['search_console']['note'], 'SEARCH_CONSOLE_ENABLED + servis hesabı JSON env\'i ve ayarlardaki mülk adresi ile bağlayın; tıklama/gösterim/sıra verisi o zaman gelir.');
        }

        if (! $integrations['analytics']['connected']) {
            $add('search_performance', 'analytics_not_connected', 'info', 'Analytics bağlı değil', null, $integrations['analytics']['note'], 'ANALYTICS_ENABLED + servis hesabı JSON env\'i ile bağlayın.');
        }

        if (! $integrations['ai']['connected']) {
            $add('ai_content', 'not_connected', 'info', 'AI sağlayıcısı bağlı değil', null, $integrations['ai']['note'], 'AI_ENABLED + AI_API_KEY env\'i ile bağlayın; içerik üretim hattı taslak üretebilir.');
        }

        return $issues;
    }

    /**
     * Yakın kopya gövdeler: 5 kelimelik parçaların Jaccard benzerliği ≥ eşik.
     *
     * @param  list<Content>  $contents
     * @return list<array{0: Content, 1: Content, 2: float}>
     */
    private function nearDuplicates(array $contents): array
    {
        $sets = [];

        foreach ($contents as $i => $content) {
            $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(strip_tags((string) $content->body))) ?: [];
            $words = array_values(array_filter($words, fn (string $w) => $w !== ''));

            if (count($words) < 40) {
                continue; // kısa gövde zaten zayıf içerik bulgusudur
            }

            $set = [];

            for ($k = 0; $k + 5 <= count($words); $k++) {
                $set[implode(' ', array_slice($words, $k, 5))] = true;
            }

            $sets[$i] = $set;
        }

        $pairs = [];
        $keys = array_keys($sets);

        for ($a = 0; $a < count($keys); $a++) {
            for ($b = $a + 1; $b < count($keys); $b++) {
                $x = $sets[$keys[$a]];
                $y = $sets[$keys[$b]];
                $intersection = count(array_intersect_key($x, $y));
                $union = count($x) + count($y) - $intersection;
                $ratio = $union > 0 ? $intersection / $union : 0.0;

                if ($ratio >= self::DUPLICATE_JACCARD) {
                    $pairs[] = [$contents[$keys[$a]], $contents[$keys[$b]], $ratio];
                }
            }
        }

        return $pairs;
    }

    private static function pathIn(string $item): ?string
    {
        return preg_match('~\((/[^\s)]*)\)~', $item, $m) === 1 ? $m[1] : null;
    }

    private static function causeFor(string $group): string
    {
        return match ($group) {
            'duplicate_title' => 'Birden fazla sayfa aynı başlığı taşıyor; arama motoru hangisini göstereceğini seçemez.',
            'duplicate_description' => 'Aynı meta açıklama birden fazla sayfada.',
            'missing_description' => 'Meta açıklama ve özet boş; sonuç sayfasında rastgele metin görünür.',
            'broken_links' => 'Gövdede var olmayan bir iç adrese bağlantı var.',
            'orphan_pages' => 'Sayfaya menüden ya da başka sayfadan bağlantı yok; tarayıcı bulamaz.',
            'redirect_chains' => 'Yönlendirme başka bir yönlendirmeye gidiyor (ayar tabanlı).',
            'images_without_alt' => 'Görsel alt metni boş; erişilebilirlik ve görsel arama kaybı.',
            'mixed_content' => 'http:// kaynak https sayfada uyarı üretir.',
            'canonical' => 'Canonical ayarları birbiriyle çelişiyor.',
            default => 'Teknik denetim bulgusu.',
        };
    }

    private static function recommendationFor(string $group): string
    {
        return match ($group) {
            'duplicate_title' => 'Her sayfaya benzersiz başlık verin (içerik formu › meta başlık).',
            'duplicate_description' => 'Açıklamaları sayfaya özgü yazın.',
            'missing_description' => 'İçerik formunda meta açıklama ya da özet girin.',
            'broken_links' => 'Bağlantıyı düzeltin ya da eski adres için yönlendirme tanımlayın.',
            'orphan_pages' => 'Menüye ekleyin ya da ilgili sayfalardan bağlantı verin.',
            'redirect_chains' => 'Kaynağı doğrudan son hedefe yönlendirin.',
            'images_without_alt' => 'Görsele açıklayıcı alt metin yazın.',
            'mixed_content' => 'Kaynağı https:// ile değiştirin.',
            'canonical' => 'Canonical & URL sekmesini düzeltin (JIT).',
            default => 'Teknik sekmesindeki ayrıntıya bakın.',
        };
    }
}
