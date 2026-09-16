<?php

namespace App\Services;

use App\Models\SiteBlock;
use App\Models\User;
use App\Models\Website;
use DomainException;

/**
 * Vitrin blokları (faz 10): ana sayfanın pazarlama listeleri (çözümler,
 * toplantı odaları, dahil olanlar, üyelik tablosu, footer sütunları) CMS'ten
 * düzenlenir. Kayıt yoksa config/ofisvio.php varsayılanı geçerlidir —
 * geçiş kırılmaz, her blok tek tek devralınır.
 *
 * Panelde bloklar satır tabanlı metin olarak düzenlenir ("Alan | Alan | …");
 * JSON editörü yok. parse() her satırı doğrular, bozuk satır DomainException.
 * Bloklar doğrudan canlıya çıkar (akış yok) — yetki route'ta content.publish.
 */
class SiteBlockService
{
    /** blok anahtarı => [etiket, alan başlıkları, config anahtarı] */
    public const BLOCKS = [
        'solutions' => ['label' => 'Çözümler', 'fields' => ['Başlık', 'Açıklama', 'Fiyat', 'Amiral (evet/hayır)'], 'config' => 'solutions'],
        'room_types' => ['label' => 'Toplantı odaları', 'fields' => ['Başlık', 'Kapasite/donanım', 'Fiyat'], 'config' => 'room_types'],
        'amenities' => ['label' => 'Dahil olanlar', 'fields' => ['Başlık', 'Açıklama'], 'config' => 'amenities'],
        'plans' => ['label' => 'Üyelik planları (sütunlar)', 'fields' => ['Plan', 'Fiyat'], 'config' => 'plans'],
        'plan_rows' => ['label' => 'Üyelik karşılaştırma satırları', 'fields' => ['Özellik', 'Plan başına hücre…'], 'config' => 'plan_rows'],
        'footer_columns' => ['label' => 'Footer sütunları', 'fields' => ['Başlık', 'Madde, madde, …'], 'config' => 'footer_columns'],
    ];

    public function __construct(private readonly ContentCache $cache) {}

    /**
     * Tüm bloklar (kayıt ya da config varsayılanı) + pricing_note. Önbellekli;
     * site sürümüyle geçersizlenir.
     *
     * @return array<string, mixed>
     */
    public function all(?Website $website): array
    {
        $defaults = [];

        foreach (self::BLOCKS as $key => $meta) {
            $defaults[$key] = (array) config('ofisvio.'.$meta['config']);
        }

        $defaults['pricing_note'] = (string) config('ofisvio.pricing_note');

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

    /** Bloğun düzenleme metni: kayıt varsa ondan, yoksa config'ten. */
    public function text(Website $website, string $key): string
    {
        $data = $this->all($website)[$key] ?? [];

        if ($key === 'pricing_note') {
            return (string) $data;
        }

        return implode("\n", array_map(fn (array $row) => implode(' | ', $this->rowToCells($key, $row)), (array) $data));
    }

    /**
     * Metni çözüp kaydeder. Boş metin = kaydı sil (config varsayılanına dön).
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

    /** Kayıtlı (config'ten sapan) blok anahtarları. @return array<int, string> */
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
                'solutions' => $this->cellsOrFail($cells, 4, $n, $expected, fn () => [
                    'key' => str($cells[0])->slug()->toString(),
                    'title' => $cells[0], 'desc' => $cells[1], 'price' => $cells[2],
                    'flagship' => in_array(mb_strtolower($cells[3]), ['evet', 'e', 'yes', '1'], true),
                ]),
                'room_types' => $this->cellsOrFail($cells, 3, $n, $expected, fn () => ['title' => $cells[0], 'meta' => $cells[1], 'price' => $cells[2]]),
                'amenities' => $this->cellsOrFail($cells, 2, $n, $expected, fn () => ['title' => $cells[0], 'desc' => $cells[1]]),
                'plans' => $this->cellsOrFail($cells, 2, $n, $expected, fn () => ['name' => $cells[0], 'price' => $cells[1]]),
                'plan_rows' => count($cells) >= 2
                    ? ['label' => $cells[0], 'cells' => array_slice($cells, 1)]
                    : throw new DomainException("{$n}. satır: Özellik | hücre | hücre … biçiminde olmalı."),
                'footer_columns' => count($cells) === 2
                    ? ['title' => $cells[0], 'items' => array_values(array_filter(array_map('trim', explode(',', $cells[1]))))]
                    : throw new DomainException("{$n}. satır: Başlık | madde, madde biçiminde olmalı."),
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
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    private function rowToCells(string $key, array $row): array
    {
        return match ($key) {
            'solutions' => [(string) $row['title'], (string) $row['desc'], (string) $row['price'], ! empty($row['flagship']) ? 'evet' : 'hayır'],
            'room_types' => [(string) $row['title'], (string) $row['meta'], (string) $row['price']],
            'amenities' => [(string) $row['title'], (string) $row['desc']],
            'plans' => [(string) $row['name'], (string) $row['price']],
            'plan_rows' => array_merge([(string) $row['label']], array_map('strval', (array) $row['cells'])),
            'footer_columns' => [(string) $row['title'], implode(', ', (array) $row['items'])],
            default => [],
        };
    }
}
