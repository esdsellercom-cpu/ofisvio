<?php

namespace App\Seo;

/**
 * GEO Answer Engine (faz 60b): hizmet başına yapılandırılmış cevap alanları. Üretken arama motorları
 * ("X nedir?", "kimler için?", "hangi belgeler gerekir?") bu bölümlerden alıntı yapar; hizmet sayfasında başlıklı
 * bölümler, FAQPage şeması ve llms.txt özeti buradan beslenir. Tanım tek yerde: form, normalizasyon, görünüm.
 */
class GeoAnswers
{
    /** alan => [başlık, tür (text|list), yardım] */
    public const FIELDS = [
        'what' => ['Nedir?', 'text', 'Hizmetin bir-iki paragraflık tanımı; ilk cümle tek başına cevap olabilmeli.'],
        'who' => ['Kimler için?', 'text', 'Hedef kullanıcı: yeni kurulan şirket, serbest çalışan, şube açan firma…'],
        'how' => ['Nasıl çalışır?', 'text', 'Başvurudan kullanıma akış; gerçek adımlar.'],
        'where' => ['Nerede?', 'text', 'Sunulduğu şube/şehir; boşsa yayındaki lokasyonlardan otomatik türer.'],
        'pricing' => ['Fiyatlandırma', 'text', 'Fiyat mantığı (aylık/saatlik, neyi kapsar). Tutar yazacaksanız güncel tutun; uydurma rakam yok.'],
        'requirements' => ['Gereksinimler', 'list', 'Satır başına bir madde.'],
        'documents' => ['Gerekli belgeler', 'list', 'Satır başına bir belge (imza sirküleri, vergi levhası…).'],
        'process' => ['Süreç', 'list', 'Satır başına bir adım, sırayla.'],
        'advantages' => ['Avantajlar', 'list', 'Satır başına bir avantaj.'],
        'limitations' => ['Sınırlamalar', 'list', 'Neyi kapsamaz? Dürüst sınırlar güven verir.'],
    ];

    public const FAQ_ROWS = 8;

    public const MAX_TEXT = 2000;

    public const MAX_LIST_ITEMS = 20;

    /**
     * Form girdisini normalize eder: metinler kırpılır, listeler satırlara bölünür, SSS boş satırlar atılır,
     * ilişkiler tam sayı kimliklere indirgenir (varlık denetimi serviste).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        $out = [];

        foreach (self::FIELDS as $key => [, $type]) {
            $raw = $input[$key] ?? '';

            if ($type === 'list') {
                $items = is_array($raw) ? $raw : (preg_split('/\r?\n/', (string) $raw) ?: []);
                $items = array_values(array_filter(array_map(fn ($v) => mb_substr(trim((string) $v), 0, 300), $items), fn (string $v) => $v !== ''));
                $out[$key] = array_slice($items, 0, self::MAX_LIST_ITEMS);
            } else {
                $out[$key] = mb_substr(trim((string) $raw), 0, self::MAX_TEXT);
            }
        }

        $faq = [];

        foreach ((array) ($input['faq'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $q = mb_substr(trim((string) ($row['q'] ?? '')), 0, 200);
            $a = mb_substr(trim((string) ($row['a'] ?? '')), 0, 1000);

            if ($q !== '' && $a !== '') {
                $faq[] = ['q' => $q, 'a' => $a];
            }
        }

        $out['faq'] = array_slice($faq, 0, self::FAQ_ROWS);
        $out['related_services'] = array_values(array_unique(array_map('intval', array_filter((array) ($input['related_services'] ?? []), 'is_numeric'))));
        $out['related_locations'] = array_values(array_unique(array_map('intval', array_filter((array) ($input['related_locations'] ?? []), 'is_numeric'))));

        return $out;
    }

    /**
     * Dolu alan sayısı (13 üzerinden) — GEO kapsam ölçüsü.
     *
     * @param  array<string, mixed>|null  $answers
     */
    public static function filled(?array $answers): int
    {
        if ($answers === null) {
            return 0;
        }

        $n = 0;

        foreach (array_merge(array_keys(self::FIELDS), ['faq', 'related_services', 'related_locations']) as $key) {
            $value = $answers[$key] ?? null;

            if ((is_string($value) && trim($value) !== '') || (is_array($value) && $value !== [])) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Sayfada basılacak bölümler: yalnız dolu olanlar, tanım sırasıyla.
     *
     * @param  array<string, mixed>|null  $answers
     * @return list<array{key: string, title: string, type: string, value: string|list<string>}>
     */
    public static function sections(?array $answers): array
    {
        $out = [];

        foreach (self::FIELDS as $key => [$title, $type]) {
            $value = $answers[$key] ?? null;

            if ($type === 'list' ? (is_array($value) && $value !== []) : (is_string($value) && trim($value) !== '')) {
                $out[] = ['key' => $key, 'title' => $title, 'type' => $type, 'value' => $type === 'list' ? array_values(array_map('strval', $value)) : trim($value)];
            }
        }

        return $out;
    }
}
