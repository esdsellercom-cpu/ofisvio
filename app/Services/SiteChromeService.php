<?php

namespace App\Services;

use App\Events\SiteChromePublished;
use App\Models\Content;
use App\Models\Media;
use App\Models\SiteChromeVersion;
use App\Models\User;
use App\Models\Website;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Global header / footer ("site chrome") yönetimi (faz 61a). Tek kaynak: `websites.header_config` /
 * `footer_config` (JSON, şema burada); taslak `builder_globals.header|footer` (görsel editör yayınıyla birlikte
 * canlıya geçer); her yayından önce `site_chrome_versions`'a sürüm düşer (geri alma). Menü boşsa vitrin eski
 * davranışla yayınlanmış bölüm çapalarından türetir; footer sütunları boşsa `footer_columns` bloğu kullanılır.
 * Yazma yalnız burada (audit). Değerler doğrulanıp temizlenir: adresler site içi yol / https / #çapa / mailto / tel,
 * renkler hex, yükseklik 56–120 px, logo medya kütüphanesinden.
 */
class SiteChromeService
{
    public const AREAS = ['header', 'footer'];

    public const SOCIAL = ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'linkedin' => 'LinkedIn', 'x' => 'X', 'youtube' => 'YouTube', 'tiktok' => 'TikTok', 'whatsapp' => 'WhatsApp'];

    public const HEADER_DEFAULTS = [
        'logo_media_id' => null,
        'logo_mobile_media_id' => null,
        'logo_height' => 34,
        'menu' => [],            // boş = yayınlanmış bölüm çapaları (otomatik)
        'cta' => ['label' => '', 'href' => '#teklif', 'style' => 'brand'], // label boş = texts.cta_header
        'show_login' => true,
        'show_phone' => false,
        'show_email' => false,
        'social' => [],
        'sticky' => true,
        'transparent' => false,
        'height' => 74,
        'colors' => ['bg' => '', 'text' => '', 'hover' => '', 'active' => ''],
        'active_style' => 'underline', // underline | pill | bold
        'mobile_show_locations' => true,
    ];

    public const FOOTER_DEFAULTS = [
        'logo_media_id' => null,
        'description' => '',      // boş = websites.tagline
        'columns' => [],          // boş = footer_columns bloğu
        'show_contact' => true,
        'show_hours' => true,
        'show_address' => true,
        'social' => [],
        'cta' => ['label' => '', 'href' => ''],
        'newsletter' => ['enabled' => false, 'title' => '', 'text' => ''],
        'legal' => ['kvkk' => null, 'privacy' => null, 'cookies' => null, 'terms' => null], // içerik kimlikleri
        'copyright' => '',        // boş = © yıl tüzel ad
        'bottom_text' => '',
        'show_location' => true,
        'cookie_notice' => ['text' => '', 'accept' => '', 'decline' => ''], // çerez rıza bandı metinleri (boş = varsayılan; audit F-06)
    ];

    public function __construct(private readonly AuditService $audit, private readonly ContentCache $cache) {}

    /**
     * Çözülmüş yapılandırma (varsayılan + kayıt [+ taslak]).
     *
     * @return array<string, mixed>
     */
    public function config(Website $website, string $area, bool $withDraft = false): array
    {
        self::assertArea($area);
        $defaults = $area === 'header' ? self::HEADER_DEFAULTS : self::FOOTER_DEFAULTS;
        $stored = (array) ($website->{$area.'_config'} ?? []);

        if ($withDraft && is_array($website->builder_globals[$area] ?? null)) {
            $stored = $website->builder_globals[$area];
        }

        return array_replace_recursive($defaults, array_intersect_key($stored, $defaults));
    }

    public function hasDraft(Website $website, string $area): bool
    {
        return is_array($website->builder_globals[$area] ?? null);
    }

    /** Yayın: önce sürüm düşer, sonra yapılandırma yazılır; taslak temizlenir. Audit (before/after). */
    public function publish(User $actor, Website $website, string $area, array $input, ?string $note = null): array
    {
        self::assertArea($area);
        $config = $this->normalize($website, $area, $input);
        $before = (array) ($website->{$area.'_config'} ?? []);

        DB::transaction(function () use ($actor, $website, $area, $config, $before, $note) {
            if ($before !== []) {
                $number = (int) SiteChromeVersion::query()->where('website_id', $website->id)->where('area', $area)->lockForUpdate()->max('number') + 1;
                SiteChromeVersion::query()->create(['website_id' => $website->id, 'area' => $area, 'number' => $number, 'config' => $before, 'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 200) : null, 'created_by' => $actor->id]);
            }

            $globals = (array) ($website->builder_globals ?? []);
            unset($globals[$area]);
            $website->forceFill([$area.'_config' => $config, 'builder_globals' => $globals === [] ? null : $globals])->save();
        });

        $this->cache->invalidate($website);
        $this->audit->record($actor, 'site.chrome_published', 'website', $website->id, ['area' => $area, 'config' => $before], ['area' => $area, 'config' => $config, 'note' => $note]);
        event(new SiteChromePublished($website, $area, $actor)); // yasal metin sürümü (audit F-07)

        return $config;
    }

    /** Taslak (önizleme için): builder_globals[area]; görsel editör "Yayınla" ya da bu ekranın "Yayınla"sı canlıya alır. */
    public function saveDraft(User $actor, Website $website, string $area, array $input): array
    {
        self::assertArea($area);
        $config = $this->normalize($website, $area, $input);
        $globals = (array) ($website->builder_globals ?? []);
        $globals[$area] = $config;
        $website->forceFill(['builder_globals' => $globals])->save();
        $this->audit->record($actor, 'site.chrome_draft_saved', 'website', $website->id, [], ['area' => $area]);

        return $config;
    }

    public function discardDraft(User $actor, Website $website, string $area): void
    {
        self::assertArea($area);
        $globals = (array) ($website->builder_globals ?? []);
        unset($globals[$area]);
        $website->forceFill(['builder_globals' => $globals === [] ? null : $globals])->save();
        $this->audit->record($actor, 'site.chrome_draft_discarded', 'website', $website->id, [], ['area' => $area]);
    }

    /** Görsel editör yayınında taslak header/footer'ı canlıya alır (SiteBuilderService::publishGlobals çağırır). */
    public function publishDrafts(User $actor, Website $website): void
    {
        foreach (self::AREAS as $area) {
            if ($this->hasDraft($website, $area)) {
                $this->publish($actor, $website->fresh(), $area, $website->builder_globals[$area], 'Görsel editör yayını');
            }
        }
    }

    /** @return Collection<int, SiteChromeVersion> */
    public function versions(Website $website, string $area, int $limit = 20): Collection
    {
        self::assertArea($area);

        return SiteChromeVersion::query()->where('website_id', $website->id)->where('area', $area)->with('author')->orderByDesc('number')->limit($limit)->get();
    }

    /** Geri alma: seçilen sürüm yeniden yayınlanır (mevcut yapılandırma yeni sürüm olarak saklanır). */
    public function rollback(User $actor, Website $website, string $area, int $versionId): array
    {
        $version = SiteChromeVersion::query()->where('website_id', $website->id)->where('area', $area)->find($versionId);

        if ($version === null) {
            throw new DomainException('Sürüm bulunamadı.');
        }

        return $this->publish($actor, $website, $area, (array) $version->config, 'Geri alma: sürüm '.$version->number);
    }

    // ---- normalizasyon ------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function normalize(Website $website, string $area, array $input): array
    {
        return $area === 'header' ? $this->normalizeHeader($website, $input) : $this->normalizeFooter($website, $input);
    }

    /** @return array<string, mixed> */
    private function normalizeHeader(Website $website, array $in): array
    {
        $menu = [];

        foreach (array_slice(array_values(array_filter((array) ($in['menu'] ?? []), 'is_array')), 0, 12) as $item) {
            $label = self::text($item['label'] ?? '', 60);
            $href = self::href($item['href'] ?? '');

            if ($label === '' || $href === '') {
                continue;
            }

            $children = [];

            foreach (array_slice(array_values(array_filter((array) ($item['children'] ?? []), 'is_array')), 0, 12) as $child) {
                $cl = self::text($child['label'] ?? '', 60);
                $ch = self::href($child['href'] ?? '');

                if ($cl !== '' && $ch !== '') {
                    $children[] = ['label' => $cl, 'href' => $ch, 'description' => self::text($child['description'] ?? '', 120)];
                }
            }

            $menu[] = ['label' => $label, 'href' => $href, 'children' => $children, 'mega' => (bool) ($item['mega'] ?? false) && $children !== [], 'new_tab' => (bool) ($item['new_tab'] ?? false)];
        }

        $colors = [];

        foreach (['bg', 'text', 'hover', 'active'] as $key) {
            $colors[$key] = self::color($in['colors'][$key] ?? '');
        }

        return [
            'logo_media_id' => $this->mediaId($website, $in['logo_media_id'] ?? null),
            'logo_mobile_media_id' => $this->mediaId($website, $in['logo_mobile_media_id'] ?? null),
            'logo_height' => max(20, min(80, (int) ($in['logo_height'] ?? 34))),
            'menu' => $menu,
            'cta' => ['label' => self::text($in['cta']['label'] ?? '', 40), 'href' => self::href($in['cta']['href'] ?? '#teklif') ?: '#teklif', 'style' => in_array($in['cta']['style'] ?? '', ['brand', 'ghost'], true) ? $in['cta']['style'] : 'brand'],
            'show_login' => (bool) ($in['show_login'] ?? true),
            'show_phone' => (bool) ($in['show_phone'] ?? false),
            'show_email' => (bool) ($in['show_email'] ?? false),
            'social' => self::social($in['social'] ?? []),
            'sticky' => (bool) ($in['sticky'] ?? true),
            'transparent' => (bool) ($in['transparent'] ?? false),
            'height' => max(56, min(120, (int) ($in['height'] ?? 74))),
            'colors' => $colors,
            'active_style' => in_array($in['active_style'] ?? '', ['underline', 'pill', 'bold'], true) ? $in['active_style'] : 'underline',
            'mobile_show_locations' => (bool) ($in['mobile_show_locations'] ?? true),
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeFooter(Website $website, array $in): array
    {
        $columns = [];

        foreach (array_slice(array_values(array_filter((array) ($in['columns'] ?? []), 'is_array')), 0, 6) as $column) {
            $title = self::text($column['title'] ?? '', 60);
            $items = [];

            foreach (array_slice(array_values(array_filter((array) ($column['items'] ?? []), 'is_array')), 0, 12) as $item) {
                $label = self::text($item['label'] ?? '', 80);

                if ($label !== '') {
                    $items[] = ['label' => $label, 'href' => self::href($item['href'] ?? '')];
                }
            }

            if ($title !== '' && $items !== []) {
                $columns[] = ['title' => $title, 'items' => $items];
            }
        }

        $legal = [];

        foreach (['kvkk', 'privacy', 'cookies', 'terms'] as $key) {
            $id = (int) ($in['legal'][$key] ?? 0);
            $legal[$key] = $id > 0 && Content::query()->where('website_id', $website->id)->whereKey($id)->exists() ? $id : null;
        }

        return [
            'logo_media_id' => $this->mediaId($website, $in['logo_media_id'] ?? null),
            'description' => self::text($in['description'] ?? '', 300),
            'columns' => $columns,
            'show_contact' => (bool) ($in['show_contact'] ?? true),
            'show_hours' => (bool) ($in['show_hours'] ?? true),
            'show_address' => (bool) ($in['show_address'] ?? true),
            'social' => self::social($in['social'] ?? []),
            'cta' => ['label' => self::text($in['cta']['label'] ?? '', 40), 'href' => self::href($in['cta']['href'] ?? '')],
            'newsletter' => ['enabled' => (bool) ($in['newsletter']['enabled'] ?? false), 'title' => self::text($in['newsletter']['title'] ?? '', 60), 'text' => self::text($in['newsletter']['text'] ?? '', 200)],
            'legal' => $legal,
            'copyright' => self::text($in['copyright'] ?? '', 120),
            'bottom_text' => self::text($in['bottom_text'] ?? '', 200),
            'show_location' => (bool) ($in['show_location'] ?? true),
            'cookie_notice' => ['text' => self::text($in['cookie_notice']['text'] ?? '', 300), 'accept' => self::text($in['cookie_notice']['accept'] ?? '', 40), 'decline' => self::text($in['cookie_notice']['decline'] ?? '', 40)],
        ];
    }

    private function mediaId(Website $website, mixed $value): ?int
    {
        $id = (int) $value;

        if ($id <= 0) {
            return null;
        }

        return Media::query()->where('website_id', $website->id)->whereKey($id)->exists() ? $id : null;
    }

    /** @return list<array{network: string, url: string}> */
    private static function social(mixed $rows): array
    {
        $out = [];

        foreach ((array) $rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $network = (string) ($row['network'] ?? '');
            $url = trim((string) ($row['url'] ?? ''));

            if (isset(self::SOCIAL[$network]) && preg_match('#^https://[^\s]+$#', $url) === 1) {
                $out[] = ['network' => $network, 'url' => mb_substr($url, 0, 300)];
            }
        }

        return array_slice($out, 0, 8);
    }

    private static function text(mixed $value, int $max): string
    {
        return mb_substr(trim(strip_tags((string) $value)), 0, $max);
    }

    /** Site içi yol, çapa, https adres, mailto/tel; diğerleri boş (javascript: vb. giremez). */
    public static function href(mixed $value): string
    {
        $href = trim((string) $value);

        if ($href === '' || preg_match('#^(/(?!/)[^\s]*|\#[a-z0-9_-]+|https://[^\s]+|mailto:[^\s]+|tel:[+0-9() -]+)$#i', $href) !== 1) {
            return '';
        }

        return mb_substr($href, 0, 300);
    }

    private static function color(mixed $value): string
    {
        $color = trim((string) $value);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? strtolower($color) : '';
    }

    private static function assertArea(string $area): void
    {
        if (! in_array($area, self::AREAS, true)) {
            throw new DomainException('Geçersiz alan.');
        }
    }
}
