<?php

namespace App\View\Menu;

use App\Models\Location;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Panel kenar menüsü (faz 38 — "Kolektif Panel" kalıbı): gruplar → numaralı
 * ögeler → rozet. Yalnız VAR OLAN modüller; ölü menü yok. Görünürlük izne göre
 * (personel mi'ye göre değil): finance_admin personeldir ama KYC kuyruğunu
 * göremez. Gate::after boş context'te de global izinleri doğru cevaplar.
 *
 * Numara, süzülmüş listede sırayla verilir (kullanıcı 1..N görür; boşluk yok).
 *
 * @phpstan-type Item array{n: int, label: string, url: string, active: bool, badge: int, tone: string}
 * @phpstan-type Group array{label: string, items: array<int, Item>}
 */
class PanelMenu
{
    public function __construct(private readonly Gate $gate, private readonly Request $request, private readonly AuthorizationService $authorization) {}

    /**
     * @param  Collection<int, Location>  $deskLocations
     * @param  array<string, int>  $badges
     * @return array<int, Group>
     */
    public function build(User $user, bool $twoFactorRequired, bool $hasOrganization, Collection $deskLocations, array $badges): array
    {
        if ($twoFactorRequired) {
            // 2FA kurulmadan personel hiçbir modüle giremez (staff.2fa); menüde yalnız kurulum adımı.
            return $this->number([
                ['Kurulum', [
                    $this->item('İki adımlı doğrulamayı kur', route('panel.account.security'), $this->routeIs('panel.account.security')),
                    $this->item('Hesabım', route('panel.account'), $this->routeIs('panel.account')),
                ]],
            ]);
        }

        $gate = $this->gate->forUser($user);
        $can = fn (string ...$perms) => $gate->any($perms);
        $kind = fn (string $k) => $this->routeIs('panel.content.index', 'panel.content.create', 'panel.content.show', 'panel.content.edit') && $this->request->query('kind') === $k;
        // Gelişmiş SEO sekmesi etkin mi (menü ögeleri sekme gruplarına bölünür).
        $seoTab = fn (string ...$tabs) => $this->routeIs('panel.seo.settings.show', 'panel.seo.settings.home') && in_array((string) ($this->request->route('sekme') ?? $this->request->query('sekme', 'tarama')), $tabs, true);
        $contentPerms = ['content.view', 'content.edit', 'content.review', 'content.approve', 'content.publish', 'content.schedule', 'content.archive'];

        // Lokasyon kapsamlı personel (resepsiyon): yalnız kendi şubesinin masası.
        $desk = $deskLocations->map(fn (Location $l) => $this->item(
            $l->name.' masası',
            route('panel.bookings.location', $l),
            $this->routeIs('panel.bookings.location*', 'panel.bookings.calendar', 'panel.bookings.show') && $this->request->route('location')?->id === $l->id,
        ))->all();

        // Artifact ("Kolektif Panel") sırası: Genel bakış · Operasyon · Üyelik · Finans · Büyüme · Dijital · Sistem.
        // Yalnız var olan modüller; Finans/Etkinlik/Franchise ögeleri kendi fazlarında eklenir.
        $groups = [
            ['Genel bakış', [
                $can('booking.view', 'invoice.view', 'subscription.view', 'space.view', 'lead.view', 'event.view', 'franchise.view', 'kyc.view_status', 'notification.view', 'geo.view') ? $this->item('Operasyon paneli', route('panel.operations'), $this->routeIs('panel.operations')) : null,
                $hasOrganization ? $this->item('Dashboard', route('panel.dashboard'), $this->routeIs('panel.dashboard')) : null,
            ]],
            ['Operasyon', [
                $can('geo.view') ? $this->item('Lokasyonlar & alanlar', route('panel.geo.index'), $this->routeIs('panel.geo.*')) : null,
                $can('geo.view', 'booking.view') || $this->authorization->canAnywhere($user, 'space.view') ? $this->item('Masalar, ofisler & odalar', route('panel.spaces.index'), $this->routeIs('panel.spaces.*')) : null,
                $can('booking.view') ? $this->item('Rezervasyonlar', route('panel.bookings.index'), $this->routeIs('panel.bookings.index'), $badges['bookings_pending'] ?? 0, 'w') : null,
                ...$desk,
                $can('service.view', 'service.manage') ? $this->item('Hizmet kataloğu', route('panel.services.index'), $this->routeIs('panel.services.*')) : null,
            ]],
            ['Üyelik', [
                $hasOrganization ? $this->item('Şirketler', route('panel.companies.index'), $this->routeIs('panel.companies.*')) : null,
                $hasOrganization ? $this->item('Üyeler & kullanıcılar', route('panel.members.index'), $this->routeIs('panel.members.*')) : null,
                $hasOrganization && $can('kyc.view_status') ? $this->item('KYC kuyruğu', route('panel.kyc.queue'), $this->routeIs('panel.kyc.queue'), $badges['kyc_pending'] ?? 0, 'w') : null,
                $can('subscription.view') ? $this->item('Üyelikler & paketler', route('panel.subscriptions.index'), $this->routeIs('panel.subscriptions.*', 'panel.plans.*'), $badges['subscriptions_expiring'] ?? 0, 'w') : null,
            ]],
            ['Finans', [
                $can('invoice.view') ? $this->item('Tahsilat & üyelik takibi', route('panel.collections.index'), $this->routeIs('panel.collections.*'), $badges['invoices_overdue'] ?? 0, 'c') : null,
                $can('invoice.view') ? $this->item('Ödemeler & faturalandırma', route('panel.invoices.index'), $this->routeIs('panel.invoices.*')) : null,
                $can('ledger.view') ? $this->item('Muhasebe defteri', route('panel.collections.ledger'), $this->routeIs('panel.collections.ledger')) : null,
            ]],
            ['Büyüme', [
                $can('event.view', 'event.manage') ? $this->item('Etkinlikler & topluluk', route('panel.events.index'), $this->routeIs('panel.events.*')) : null,
                $can('lead.view') ? $this->item('CRM & pazarlama', route('panel.leads.index'), $this->routeIs('panel.leads.*'), $badges['leads_new'] ?? 0, 'a') : null,
                $can('franchise.view', 'franchise.manage') ? $this->item('Franchise yönetimi', route('panel.franchise.index'), $this->routeIs('panel.franchise.*'), $badges['franchise_new'] ?? 0, 'a') : null,
            ]],
            ['Dijital', [
                $can(...$contentPerms) ? $this->item('Sayfalar', route('panel.content.index', ['kind' => 'page']), $kind('page')) : null,
                $can(...$contentPerms) ? $this->item('Yazılar', route('panel.content.index', ['kind' => 'post']), $kind('post')) : null,
                $can(...$contentPerms) ? $this->item('İçerik takvimi', route('panel.content.calendar'), $this->routeIs('panel.content.calendar')) : null,
                $can('content.edit', 'content.publish') ? $this->item('Ana sayfa tasarımı', route('panel.content.builder.index'), $this->routeIs('panel.content.builder.*')) : null,
                $can('content.edit', 'content.publish') ? $this->item('Blok kütüphanesi', route('panel.content.blocks'), $this->routeIs('panel.content.blocks*')) : null,
                $can('content.edit') ? $this->item('Menü & tema', route('panel.content.menu'), $this->routeIs('panel.content.menu')) : null,
                $can('website.view', 'website.manage', 'content.edit') ? $this->item('Header & footer', route('panel.settings.chrome.header'), $this->routeIs('panel.settings.chrome.*')) : null,
                $can('content.edit', 'content.publish') ? $this->item('Medya kütüphanesi', route('panel.content.media.index'), $this->routeIs('panel.content.media.*')) : null,
                $can('website.view', 'website.manage') ? $this->item('Websiteler', route('panel.websites.index'), $this->routeIs('panel.websites.*')) : null,
                $can('settings.view', 'settings.manage') ? $this->item('Yerelleştirme', route('panel.settings.index', ['grup' => 'general']), $this->routeIs('panel.settings.*') && $this->request->query('grup') === 'general') : null,
            ]],
            // SEO & GEO Command Center (faz 60): tek menü altında sağlık merkezi, ayar sekmeleri ve araçlar.
            ['SEO & GEO', [
                $can('seo.view') ? $this->item('Command Center', route('panel.seo.center.home'), $this->routeIs('panel.seo.center*')) : null,
                $can('seo.view') ? $this->item('SEO genel ayarlar', route('panel.seo.index'), $this->routeIs('panel.seo.index', 'panel.seo.audit')) : null,
                $can('seo.view') ? $this->item('Teknik SEO', route('panel.seo.settings.home', ['sekme' => 'teknik']), $seoTab('teknik', 'guvenlik', 'gelistirici')) : null,
                $can('seo.view') ? $this->item('Metadata', route('panel.seo.settings.home', ['sekme' => 'meta']), $seoTab('meta')) : null,
                $can('seo.view') ? $this->item('URL & yönlendirmeler', route('panel.seo.redirects.home'), $this->routeIs('panel.seo.redirects.*'), $badges['redirects_pending'] ?? 0, 'w') : null,
                $can('seo.view') ? $this->item('Sitemap & robots', route('panel.seo.settings.home', ['sekme' => 'tarama']), $seoTab('tarama')) : null,
                $can('seo.view') ? $this->item('Canonical & hreflang', route('panel.seo.settings.home', ['sekme' => 'url']), $seoTab('url', 'dil')) : null,
                $can('seo.view') ? $this->item('Schema Manager', route('panel.seo.schema.home'), $this->routeIs('panel.seo.schema*')) : null,
                $can('seo.view') ? $this->item('Entity / Knowledge Graph', route('panel.seo.entities.home'), $this->routeIs('panel.seo.entities*') || $seoTab('varlik', 'yerel')) : null,
                $can('seo.view') ? $this->item('GEO Manager', route('panel.seo.geo.home'), $this->routeIs('panel.seo.geo*') || $seoTab('geo')) : null,
                $can('seo.view') ? $this->item('Programatik SEO', route('panel.seo.landing.home'), $this->routeIs('panel.seo.landing.*')) : null,
                $can('seo.view') ? $this->item('Keyword Intelligence', route('panel.seo.keywords.home'), $this->routeIs('panel.seo.keywords*')) : null,
                $can('seo.view') ? $this->item('Internal Linking', route('panel.seo.links.home'), $this->routeIs('panel.seo.links*') || $seoTab('baglanti')) : null,
                $can('seo.analytics.view') ? $this->item('Search Console', route('panel.seo.search-console.home'), $this->routeIs('panel.seo.search-console*')) : null,
                $can('seo.analytics.view') ? $this->item('Analytics', route('panel.seo.analytics.home'), $this->routeIs('panel.seo.analytics*')) : null,
                $can('ai_content.generate', 'ai_content.review', 'ai_content.approve', 'ai_content.publish') ? $this->item('AI Content', route('panel.seo.ai.home'), $this->routeIs('panel.seo.ai.index', 'panel.seo.ai.show', 'panel.seo.ai.home')) : null,
                $can('seo.view') ? $this->item('İçerik yenileme', route('panel.seo.refresh.home'), $this->routeIs('panel.seo.refresh*')) : null,
                $can('ai_content.generate') ? $this->item('Prompt Registry', route('panel.seo.ai.prompts.home'), $this->routeIs('panel.seo.ai.prompts*')) : null,
                $can('ai_content.generate', 'seo.view') ? $this->item('API kullanımı & maliyet', route('panel.seo.ai.usage.home'), $this->routeIs('panel.seo.ai.usage*')) : null,
                $can('seo.view') ? $this->item('Doğrulama & bildirim', route('panel.seo.settings.home', ['sekme' => 'dogrulama']), $seoTab('dogrulama')) : null,
            ]],
            ['Performans', [
                $can('performance.view') ? $this->item('Performance Dashboard', route('panel.performance.center'), $this->routeIs('panel.performance.center', 'panel.performance.index', 'panel.performance.doctor')) : null,
                $can('cache.view') ? $this->item('Cache Manager', route('panel.cache.index'), $this->routeIs('panel.cache.*')) : null,
                $can('performance.view', 'cache.view') ? $this->item('Cache politikaları & HTTP cache', route('panel.performance.http-cache'), $this->routeIs('panel.performance.http-cache')) : null,
                $can('performance.view') ? $this->item('Redis', route('panel.performance.redis'), $this->routeIs('panel.performance.redis')) : null,
                $can('performance.view') ? $this->item('Sorgu performansı', route('panel.performance.queries'), $this->routeIs('panel.performance.queries')) : null,
                $can('performance.view') ? $this->item('Yavaş sorgular', route('panel.performance.slow-queries'), $this->routeIs('panel.performance.slow-queries')) : null,
                $can('performance.view') ? $this->item('Asset optimizasyonu', route('panel.performance.assets'), $this->routeIs('panel.performance.assets')) : null,
                $can('performance.view') ? $this->item('Core Web Vitals', route('panel.performance.vitals'), $this->routeIs('panel.performance.vitals*')) : null,
                $can('performance.view') ? $this->item('Performans denetimi', route('panel.performance.audit'), $this->routeIs('panel.performance.audit')) : null,
            ]],
            ['Sistem', [
                $can('notification.view', 'notification.manage') ? $this->item('Bildirimler & otomasyon', route('panel.notifications.index'), $this->routeIs('panel.notifications.index'), $badges['notifications_failed'] ?? 0, 'c') : null,
                $can('analytics.view') ? $this->item('Raporlar & analitik', route('panel.reports.index'), $this->routeIs('panel.reports.*')) : null,
                $can('user.manage') ? $this->item('Roller, yetkiler & güvenlik', route('panel.users.index'), $this->routeIs('panel.users.*', 'panel.onboarding.*')) : null,
                $can('performance.view') ? $this->item('Entegrasyonlar & API', route('panel.integrations.index'), $this->routeIs('panel.integrations.*')) : null,
                $can('settings.view', 'settings.manage', 'integrations.view', 'integrations.manage') ? $this->item('Entegrasyon merkezi', route('panel.settings.api'), $this->routeIs('panel.settings.api*', 'panel.settings.integrations.*')) : null,
                $can('settings.view', 'settings.manage') ? $this->item('Sistem sağlığı', route('panel.settings.health'), $this->routeIs('panel.settings.health')) : null,
                $can('settings.view', 'settings.manage') ? $this->item('Site & sistem ayarları', route('panel.settings.index'), $this->routeIs('panel.settings.index', 'panel.settings.update') && $this->request->query('grup') !== 'general') : null,
                $can('audit.view') ? $this->item('Denetim kaydı', route('panel.audit.index'), $this->routeIs('panel.audit.*')) : null,
            ]],
            ['Hesap', [
                $this->item('Organizasyonlar', route('panel.context.select'), $this->routeIs('panel.context.*')),
                $this->item('Hesabım', route('panel.account'), $this->routeIs('panel.account*')),
                $this->item('Gelen bildirimler', route('panel.notifications.inbox'), $this->routeIs('panel.notifications.inbox'), $badges['unread'] ?? 0, 'c'),
            ]],
        ];

        return $this->number($groups);
    }

    /** @return array{label: string, url: string, active: bool, badge: int, tone: string} */
    private function item(string $label, string $url, bool $active, int $badge = 0, string $tone = 'c'): array
    {
        return ['label' => $label, 'url' => $url, 'active' => $active, 'badge' => $badge, 'tone' => $tone];
    }

    private function routeIs(string ...$patterns): bool
    {
        return $this->request->routeIs(...$patterns);
    }

    /**
     * Boş ögeleri/grupları at, sırayla numarala.
     *
     * @param  array<int, array{0: string, 1: array<int, array{label: string, url: string, active: bool, badge: int, tone: string}|null>}>  $groups
     * @return array<int, Group>
     */
    private function number(array $groups): array
    {
        $n = 0;
        $out = [];

        foreach ($groups as [$label, $items]) {
            $items = array_values(array_filter($items));

            if ($items === []) {
                continue;
            }

            $out[] = ['label' => $label, 'items' => array_map(function (array $item) use (&$n) {
                $item['n'] = ++$n;

                return $item;
            }, $items)];
        }

        return $out;
    }
}
