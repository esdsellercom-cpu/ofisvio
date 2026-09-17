<?php

namespace App\Services;

use App\Models\Content;
use App\Models\Website;

/**
 * Otomatik iç bağlantı (faz 44): panelde tanımlı "anahtar kelime → adres" eşlemesi, süzülmüş içerik HTML'ine
 * uygulanır. Yalnız metin düğümlerinde, kelime sınırında, büyük/küçük harf duyarsız; <a>, başlık (h1–h6),
 * <code>/<pre> içine dokunulmaz; sayfa kendi adresine bağlanmaz; sayfa/hedef başına üst sınır ayardan.
 * Görsel tembel yükleme de (technical.lazy_images) aynı geçişte eklenir.
 */
class InternalLinkService
{
    public function __construct(private readonly SeoSettingsService $settings) {}

    public function apply(Website $website, Content $content, string $html): string
    {
        $s = $this->settings->for($website);

        if ($s['technical.lazy_images']) {
            $html = (string) preg_replace('/<img(?![^>]*\bloading=)/i', '<img loading="lazy" decoding="async"', $html);
        }

        if (! $s['links.auto_enabled']) {
            return $html;
        }

        $rules = [];
        $selfPath = $content->path();

        foreach ((array) $s['links.keywords'] as $row) {
            if (! is_array($row) || trim((string) ($row['keyword'] ?? '')) === '' || trim((string) ($row['url'] ?? '')) === '') {
                continue;
            }

            $url = trim((string) $row['url']);

            if ($url === $selfPath || $url === $website->baseUrl().$selfPath) {
                continue;
            }

            $rules[] = ['keyword' => trim((string) $row['keyword']), 'url' => $url];
        }

        if ($rules === []) {
            return $html;
        }

        $maxPage = (int) $s['links.max_per_page'];
        $maxTarget = max(1, (int) $s['links.max_per_target']);
        $count = 0;
        $perTarget = [];

        // Parçala: etiketler ve korunan bloklar (a, h1-6, code, pre) olduğu gibi; yalnız serbest metin işlenir.
        $parts = preg_split('#(<(?:a|h[1-6]|code|pre)\b[^>]*>.*?</(?:a|h[1-6]|code|pre)>|<[^>]+>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];

        // Kural sırasıyla: ilk uygun metin parçasında bir kez bağla; bağlanan parça üçe bölünür ki
        // sonraki kurallar yeni <a> içine giremesin.
        foreach ($rules as $rule) {
            if ($count >= $maxPage) {
                break;
            }

            if (($perTarget[$rule['url']] ?? 0) >= $maxTarget) {
                continue;
            }

            $pattern = '/(?<![\p{L}\p{N}])('.preg_quote($rule['keyword'], '/').')(?![\p{L}\p{N}])/iu';

            foreach ($parts as $i => $part) {
                if ($part === '' || $part[0] === '<' || preg_match($pattern, $part, $m, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }

                $offset = (int) $m[1][1];
                $anchor = '<a href="'.htmlspecialchars($rule['url'], ENT_QUOTES).'">'.$m[1][0].'</a>';
                array_splice($parts, $i, 1, [substr($part, 0, $offset), $anchor, substr($part, $offset + strlen($m[1][0]))]);
                $count++;
                $perTarget[$rule['url']] = ($perTarget[$rule['url']] ?? 0) + 1;
                break;
            }
        }

        return implode('', $parts);
    }
}
