<?php

namespace App\View\Menu;

use App\Models\Location;
use App\Models\User;
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
    public function __construct(private readonly Gate $gate, private readonly Request $request) {}

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
        $contentPerms = ['content.view', 'content.edit', 'content.review', 'content.approve', 'content.publish', 'content.schedule', 'content.archive'];

        // Lokasyon kapsamlı personel (resepsiyon): yalnız kendi şubesinin masası.
        $desk = $deskLocations->map(fn (Location $l) => $this->item(
            $l->name.' masası',
            route('panel.bookings.location', $l),
            $this->routeIs('panel.bookings.location*', 'panel.bookings.calendar', 'panel.bookings.show') && $this->request->route('location')?->id === $l->id,
        ))->all();

        $groups = [
            ['Genel bakış', [
                $hasOrganization ? $this->item('Dashboard', route('panel.dashboard'), $this->routeIs('panel.dashboard')) : null,
            ]],
            ['Operasyon', [
                $can('geo.view') ? $this->item('Lokasyonlar & alanlar', route('panel.geo.index'), $this->routeIs('panel.geo.*')) : null,
                $can('service.view', 'service.manage') ? $this->item('Hizmet kataloğu', route('panel.services.index'), $this->routeIs('panel.services.*')) : null,
                $can('booking.view') ? $this->item('Rezervasyonlar', route('panel.bookings.index'), $this->routeIs('panel.bookings.index'), $badges['bookings_pending'] ?? 0, 'w') : null,
                ...$desk,
            ]],
            ['Müşteri', [
                $hasOrganization ? $this->item('Şirketler', route('panel.companies.index'), $this->routeIs('panel.companies.*')) : null,
                $hasOrganization && $can('kyc.view_status') ? $this->item('KYC kuyruğu', route('panel.kyc.queue'), $this->routeIs('panel.kyc.queue'), $badges['kyc_pending'] ?? 0, 'w') : null,
                $can('lead.view') ? $this->item('Talepler & CRM', route('panel.leads.index'), $this->routeIs('panel.leads.*'), $badges['leads_new'] ?? 0, 'a') : null,
            ]],
            ['Dijital', [
                $can(...$contentPerms) ? $this->item('Sayfalar', route('panel.content.index', ['kind' => 'page']), $kind('page')) : null,
                $can(...$contentPerms) ? $this->item('Yazılar', route('panel.content.index', ['kind' => 'post']), $kind('post')) : null,
                $can(...$contentPerms) ? $this->item('İçerik takvimi', route('panel.content.calendar'), $this->routeIs('panel.content.calendar')) : null,
                $can('content.edit', 'content.publish') ? $this->item('Ana sayfa tasarımı', route('panel.content.builder.index'), $this->routeIs('panel.content.builder.*')) : null,
                $can('content.publish') ? $this->item('Metinler & bloklar', route('panel.content.blocks'), $this->routeIs('panel.content.blocks')) : null,
                $can('content.edit') ? $this->item('Menü & tema', route('panel.content.menu'), $this->routeIs('panel.content.menu')) : null,
                $can('content.edit', 'content.publish') ? $this->item('Medya kütüphanesi', route('panel.content.media.index'), $this->routeIs('panel.content.media.*')) : null,
                $can('website.view', 'website.manage') ? $this->item('Websiteler', route('panel.websites.index'), $this->routeIs('panel.websites.*')) : null,
                $can('seo.view') ? $this->item('SEO', route('panel.seo.index'), $this->routeIs('panel.seo.*')) : null,
            ]],
            ['Sistem', [
                $can('notification.view', 'notification.manage') ? $this->item('Bildirim merkezi', route('panel.notifications.index'), $this->routeIs('panel.notifications.index'), $badges['notifications_failed'] ?? 0, 'c') : null,
                $can('settings.view', 'settings.manage') ? $this->item('Ayarlar', route('panel.settings.index'), $this->routeIs('panel.settings.*')) : null,
                $can('user.manage') ? $this->item('Kullanıcılar & roller', route('panel.users.index'), $this->routeIs('panel.users.*', 'panel.onboarding.*')) : null,
                $can('audit.view') ? $this->item('Denetim kaydı', route('panel.audit.index'), $this->routeIs('panel.audit.*')) : null,
                $can('performance.view') ? $this->item('Performans & entegrasyonlar', route('panel.performance.index'), $this->routeIs('panel.performance.*')) : null,
                $can('cache.view') ? $this->item('Önbellek', route('panel.cache.index'), $this->routeIs('panel.cache.*')) : null,
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
