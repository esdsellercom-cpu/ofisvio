<?php

namespace App\Services;

use App\Models\SiteBlock;
use App\Models\User;
use App\Models\Website;
use DomainException;

/**
 * Vitrin blokları (faz 10): ana sayfanın pazarlama listeleri (çözümler,
 * toplantı odaları, dahil olanlar, üyelik tablosu, footer sütunları) CMS'ten
 * düzenlenir. Tek kaynak site_blocks tablosudur; ilk kurulumda SiteBlockSeeder
 * doldurur. Kayıt yoksa bölüm vitrinde görünmez (kodda ticari varsayılan yok).
 *
 * Panelde bloklar satır tabanlı metin olarak düzenlenir ("Alan | Alan | …");
 * JSON editörü yok. parse() her satırı doğrular, bozuk satır DomainException.
 * Bloklar doğrudan canlıya çıkar (akış yok) — yetki route'ta content.publish.
 */
class SiteBlockService
{
    /** blok anahtarı => [etiket, alan başlıkları] — veri yalnız site_blocks tablosundan (SiteBlockSeeder açılışta doldurur) */
    public const BLOCKS = [
        'amenities' => ['label' => 'Dahil olanlar', 'fields' => ['Başlık', 'Açıklama']],
        'plans' => ['label' => 'Üyelik planları (sütunlar)', 'fields' => ['Plan', 'Fiyat']],
        'plan_rows' => ['label' => 'Üyelik karşılaştırma satırları', 'fields' => ['Özellik', 'Plan başına hücre…']],
        'footer_columns' => ['label' => 'Footer sütunları', 'fields' => ['Başlık', 'Madde, madde, …']],
    ];

    /** Ana sayfa metin anahtarları => etiket (faz 29); varsayılan config('ofisvio.texts'). */
    public const TEXT_KEYS = [
        'topbar' => 'Üst şerit mesajı',
        'hero_eyebrow' => 'Hero üst yazı',
        'hero_title' => 'Hero başlık (1. satır)',
        'hero_accent' => 'Hero vurgu kelimesi (serif)',
        'hero_title_after' => 'Hero başlık (vurgudan sonra)',
        'hero_lede' => 'Hero açıklama',
        'solutions_title' => 'Çözümler başlığı',
        'solutions_lede' => 'Çözümler açıklaması',
        'journey_title' => 'Nasıl çalışır başlığı',
        'journey_lede' => 'Nasıl çalışır açıklaması',
        'locations_title' => 'Lokasyonlar başlığı',
        'meeting_title' => 'Toplantı başlığı',
        'meeting_lede' => 'Toplantı açıklaması',
        'amenities_title' => 'Dahil olanlar başlığı',
        'pricing_title' => 'Üyelikler başlığı',
        'stats_review_time' => 'İstatistik: belge inceleme süresi',
        'nav_solutions' => 'Menü: Çözümler',
        'nav_journey' => 'Menü: Nasıl çalışır',
        'nav_locations' => 'Menü: Lokasyonlar',
        'nav_meeting' => 'Menü: Toplantı & Etkinlik',
        'nav_pricing' => 'Menü: Üyelikler',
        'cta_header' => 'CTA: üst menü düğmesi',
        'cta_topbar' => 'CTA: üst şerit bağlantısı',
        'cta_hero' => 'CTA: hero süzgeç düğmesi',
        'cta_solution' => 'CTA: çözüm kartı',
        'lead_title' => 'Teklif formu başlığı',
        'lead_lede' => 'Teklif formu açıklaması',
        'lead_claim_1' => 'Teklif vaadi 1',
        'lead_claim_2' => 'Teklif vaadi 2',
        'lead_claim_3' => 'Teklif vaadi 3',
        'booking_widget_title' => 'Ön talep aracı başlığı',
        'blog_title' => 'Yazılar bölümü başlığı',
        'whatsapp_message' => 'WhatsApp ön yazılı mesaj',
    ];

    /** Boş bırakılınca bölümü gizleyen (varsayılana dönmeyen) metinler. */
    public const OPTIONAL_TEXT_KEYS = ['lead_claim_1', 'lead_claim_2', 'lead_claim_3', 'whatsapp_message'];

    public function __construct(private readonly ContentCache $cache, private readonly ServiceService $services) {}

    /**
     * Ana sayfa metinleri: kayıt (texts bloğu) config varsayılanının üstüne.
     *
     * @return array<string, string>
     */
    public function texts(?Website $website): array
    {
        $defaults = array_map('strval', (array) config('ofisvio.texts'));
        $stored = $website === null ? [] : (array) ($this->all($website)['texts'] ?? []);

        return array_merge($defaults, array_intersect_key(array_map('strval', $stored), self::TEXT_KEYS));
    }

    /**
     * Metinleri kaydeder; varsayılanla aynı ya da boş olanlar saklanmaz.
     *
     * @param  array<string, string|null>  $values
     */
    public function updateTexts(User $editor, Website $website, array $values): void
    {
        $defaults = array_map('strval', (array) config('ofisvio.texts'));
        $data = [];

        foreach (self::TEXT_KEYS as $key => $label) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $value = trim((string) $values[$key]);
            $optional = in_array($key, self::OPTIONAL_TEXT_KEYS, true);

            // Boş: zorunlu metin varsayılana döner; isteğe bağlı metin bilinçli olarak gizlenir ('' saklanır).
            if (($value !== '' || $optional) && $value !== ($defaults[$key] ?? '')) {
                $data[$key] = $value;
            }
        }

        if ($data === []) {
            SiteBlock::query()->where('website_id', $website->id)->where('key', 'texts')->delete();
        } else {
            SiteBlock::query()->updateOrCreate(['website_id' => $website->id, 'key' => 'texts'], ['data' => $data, 'updated_by' => $editor->id]);
        }

        $this->cache->invalidate($website);
    }

    /**
     * Tüm bloklar (kayıt ya da config varsayılanı) + pricing_note. Önbellekli;
     * site sürümüyle geçersizlenir.
     *
     * @return array<string, mixed>
     */
    public function all(?Website $website): array
    {
        // Kayıt yoksa blok BOŞTUR ve vitrin o bölümü basmaz — kodda ticari varsayılan yok.
        $defaults = array_fill_keys(array_keys(self::BLOCKS), []);
        $defaults['pricing_note'] = '';

        if ($website === null) {
            return $defaults;
        }

        $stored = $this->cache->remember($website, 'blocks', fn () => SiteBlock::query()
            ->where('website_id', $website->id)
            ->get()
            ->mapWithKeys(fn (SiteBlock $b) => [$b->key => $b->data])
            ->all());

        return is_array($stored) ? array_merge($defaults, $stored) : $defaults;
    }

    /**
     * Teklif formu / süzgeç seçenekleri: aktif hizmet adları (faz 4 — Hizmetler modülü tek kaynak).
     *
     * @return array<int, string>
     */
    public function solutionOptions(?Website $website): array
    {
        return $this->services->names($website);
    }

    /** Bloğun düzenleme metni (kayıt yoksa boş). */
    public function text(Website $website, string $key): string
    {
        $data = $this->all($website)[$key] ?? [];

        if ($key === 'pricing_note') {
            return (string) $data;
        }

        return implode("\n", array_map(fn (array $row) => implode(' | ', $this->rowToCells($key, $row)), (array) $data));
    }

    /**
     * Metni çözüp kaydeder. Boş metin = kaydı sil (bölüm vitrinden kalkar).
     */
    public function update(User $editor, Website $website, string $key, string $text): void
    {
        if ($key !== 'pricing_note' && ! isset(self::BLOCKS[$key])) {
            throw new DomainException('Bilinmeyen blok: '.$key);
        }

        $data = $key === 'pricing_note' ? trim($text) : $this->parse($key, $text);

        if ($data === [] || $data === '') {
            SiteBlock::query()->where('website_id', $website->id)->where('key', $key)->delete();
        } else {
            SiteBlock::query()->updateOrCreate(
                ['website_id' => $website->id, 'key' => $key],
                ['data' => $data, 'updated_by' => $editor->id],
            );
        }

        $this->cache->invalidate($website);
    }

    /**
     * Kayıtlı blok anahtarları.
     *
     * @return array<int, string>
     */
    public function overridden(Website $website): array
    {
        return SiteBlock::query()->where('website_id', $website->id)->pluck('key')->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parse(string $key, string $text): array
    {
        $rows = [];
        $expected = count(self::BLOCKS[$key]['fields']);

        foreach (preg_split('/\r?\n/', $text) ?: [] as $i => $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $cells = array_map('trim', explode('|', $line));
            $n = $i + 1;

            $rows[] = match ($key) {
                'amenities' => $this->cellsOrFail($cells, 2, $n, $expected, fn () => ['title' => $cells[0], 'desc' => $cells[1]]),
                'plans' => $this->cellsOrFail($cells, 2, $n, $expected, fn () => ['name' => $cells[0], 'price' => $cells[1]]),
                'plan_rows' => count($cells) >= 2
                    ? ['label' => $cells[0], 'cells' => array_slice($cells, 1)]
                    : throw new DomainException("{$n}. satır: Özellik | hücre | hücre … biçiminde olmalı."),
                'footer_columns' => count($cells) === 2
                    ? ['title' => $cells[0], 'items' => $this->footerItems($cells[1], $n)]
                    : throw new DomainException("{$n}. satır: Başlık | madde = /yol, madde … biçiminde olmalı."),
                default => throw new DomainException('Bilinmeyen blok: '.$key),
            };
        }

        if (count($rows) > 24) {
            throw new DomainException('En fazla 24 satır.');
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $cells
     * @param  callable(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    private function cellsOrFail(array $cells, int $count, int $line, int $expected, callable $build): array
    {
        if (count($cells) !== $count || in_array('', $cells, true)) {
            throw new DomainException("{$line}. satır: {$expected} alan bekleniyor (| ile ayrılmış, boş alan yok).");
        }

        return $build();
    }

    /**
     * Footer maddesi: "Etiket = /yol" ya da "Etiket = #bolum" (hedefsiz madde düz metin basılır;
     * ölü "#" bağlantısı üretilmez). Yalnız site içi yol, sayfa çapası ya da https adres.
     *
     * @return array<int, array{label: string, href: string}>
     */
    private function footerItems(string $cell, int $line): array
    {
        $items = [];

        foreach (array_filter(array_map('trim', explode(',', $cell))) as $raw) {
            [$label, $href] = array_pad(array_map('trim', explode('=', $raw, 2)), 2, '');

            if ($label === '') {
                throw new DomainException("{$line}. satır: madde etiketi boş olamaz.");
            }

            if ($href !== '' && preg_match('~^(/[^\s]*|#[\w-]+|https://[^\s]+)$~u', $href) !== 1) {
                throw new DomainException("{$line}. satır: '{$label}' hedefi /yol, #bolum ya da https:// olmalı.");
            }

            $items[] = ['label' => $label, 'href' => $href];
        }

        return $items;
    }

    /**
     * Eski kayıtlar düz dize listesidir; tek biçime getirir.
     *
     * @param  array<int, mixed>  $items
     * @return array<int, array{label: string, href: string}>
     */
    public static function normalizeFooterItems(array $items): array
    {
        return array_values(array_map(fn ($i) => is_array($i)
            ? ['label' => (string) ($i['label'] ?? ''), 'href' => (string) ($i['href'] ?? '')]
            : ['label' => (string) $i, 'href' => ''], $items));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    private function rowToCells(string $key, array $row): array
    {
        return match ($key) {
            'amenities' => [(string) $row['title'], (string) $row['desc']],
            'plans' => [(string) $row['name'], (string) $row['price']],
            'plan_rows' => array_merge([(string) $row['label']], array_map('strval', (array) $row['cells'])),
            'footer_columns' => [(string) $row['title'], implode(', ', array_map(fn (array $i) => $i['href'] !== '' ? $i['label'].' = '.$i['href'] : $i['label'], self::normalizeFooterItems((array) $row['items'])))],
            default => [],
        };
    }
}
