<?php

namespace App\Content;

/**
 * Sayfa SEO analizi (faz 48): deterministik kurallar, dış servis yok. Aynı kurallar editörde JS ile canlı çalışır
 * (public/js/cms.js) — burası kayıt anındaki skor ve liste rozeti için tek kaynaktır.
 *
 * Girdi: form alanları (title, slug, excerpt, body (markdown), meta_title, meta_description, focus_keyword,
 * canonical_url, schema_types, cover) ve bağlam (site içi yollar).
 */
final class SeoAnalyzer
{
    /**
     * @param  array<string, mixed>  $f
     * @return array{score: int, checks: array<int, array{key: string, ok: bool, level: string, message: string}>}
     */
    public static function analyze(array $f): array
    {
        $title = trim((string) (($f['meta_title'] ?? '') ?: ($f['title'] ?? '')));
        $description = trim((string) (($f['meta_description'] ?? '') ?: ($f['excerpt'] ?? '')));
        $body = (string) ($f['body'] ?? '');
        $keyword = mb_strtolower(trim((string) ($f['focus_keyword'] ?? '')));
        $text = mb_strtolower(strip_tags($body));
        $words = max(1, preg_match_all('/\p{L}+/u', $text));
        $checks = [];
        $add = function (string $key, bool $ok, string $level, string $okMsg, string $badMsg) use (&$checks): void {
            $checks[] = ['key' => $key, 'ok' => $ok, 'level' => $level, 'message' => $ok ? $okMsg : $badMsg];
        };

        $tl = mb_strlen($title);
        $add('title', $tl >= 30 && $tl <= 70, 'error', "Başlık {$tl} karakter (30–70).", $tl === 0 ? 'SEO başlığı yok.' : "Başlık {$tl} karakter; 30–70 arası önerilir.");
        $dl = mb_strlen($description);
        $add('description', $dl >= 50 && $dl <= 160, 'error', "Meta açıklama {$dl} karakter (50–160).", $dl === 0 ? 'Meta açıklama yok (özet de boş).' : "Meta açıklama {$dl} karakter; 50–160 arası önerilir.");

        $h1 = preg_match_all('/^#\s+/m', $body);
        $add('h1', $h1 === 0, 'warn', 'Gövdede H1 yok; sayfa başlığı H1 (doğru).', "Gövdede {$h1} adet H1 (#) var; sayfa başlığı zaten H1 — ## ile başlayın.");
        preg_match_all('/^(#{2,6})\s+\S/m', $body, $hm);
        $levels = array_map('strlen', $hm[1]);
        $jump = false;
        $prev = 1;
        foreach ($levels as $l) {
            if ($l > $prev + 1) {
                $jump = true;
            }
            $prev = $l;
        }
        $add('headings', count($levels) >= 1 && ! $jump, 'warn', count($levels).' alt başlık, düzeyler sıralı.', count($levels) === 0 ? 'Alt başlık (##) yok; içeriği bölümleyin.' : 'Başlık düzeyleri atlıyor (örn. ## sonra ####).');

        $internal = preg_match_all('/\]\((\/[^)\s]*)\)/', $body);
        $add('internal_links', $internal >= 1, 'warn', "{$internal} iç bağlantı.", 'İç bağlantı yok; ilgili sayfalara en az bir bağlantı verin.');

        preg_match_all('/!\[([^\]]*)\]\(/', $body, $im);
        $images = count($im[0]);
        $noAlt = count(array_filter($im[1], fn (string $alt) => trim($alt) === ''));
        $add('images', $images >= 1 || ! empty($f['cover']), 'info', $images.' görsel'.(! empty($f['cover']) ? ' + kapak' : '').'.', 'Görsel yok; en az bir görsel ya da kapak ekleyin.');
        $add('alt', $noAlt === 0, 'warn', 'Tüm görsellerde alt metin var.', "{$noAlt} görselin alt metni boş.");

        if ($keyword !== '') {
            $count = mb_substr_count($text, $keyword);
            $density = $count / $words * 100;
            $inTitle = str_contains(mb_strtolower($title), $keyword);
            $inDesc = str_contains(mb_strtolower($description), $keyword);
            $inSlug = str_contains((string) ($f['slug'] ?? ''), str_replace(' ', '-', $keyword));
            $inFirst = str_contains(mb_substr($text, 0, 400), $keyword);
            $add('keyword_title', $inTitle, 'error', 'Odak kelime başlıkta.', 'Odak kelime SEO başlığında geçmiyor.');
            $add('keyword_description', $inDesc, 'warn', 'Odak kelime meta açıklamada.', 'Odak kelime meta açıklamada geçmiyor.');
            $add('keyword_slug', $inSlug, 'info', 'Odak kelime slug\'da.', 'Odak kelime slug\'da yok.');
            $add('keyword_intro', $inFirst, 'warn', 'Odak kelime giriş paragrafında.', 'Odak kelime ilk 400 karakterde geçmiyor.');
            $add('keyword_density', $count >= 1 && $density <= 3, 'warn', sprintf('Odak kelime %d kez (%%%.1f yoğunluk).', $count, $density), $count === 0 ? 'Odak kelime gövdede hiç geçmiyor.' : sprintf('Odak kelime %d kez (%%%.1f) — aşırı tekrar (>%%3).', $count, $density));
        } else {
            $add('keyword', false, 'warn', '', 'Odak anahtar kelime tanımlı değil.');
        }

        $add('length', $words >= 300, 'warn', "{$words} kelime.", "Gövde {$words} kelime; en az 300 önerilir.");
        $canonical = trim((string) ($f['canonical_url'] ?? ''));
        $add('canonical', $canonical === '' || str_starts_with($canonical, 'https://') || str_starts_with($canonical, '/'), 'info', $canonical === '' ? 'Canonical otomatik (sayfanın kendi adresi).' : 'Canonical: '.$canonical, 'Canonical adres https:// ya da / ile başlamalı.');
        $types = (array) ($f['schema_types'] ?? []);
        $add('schema', $types !== [], 'info', 'Şema: '.implode(', ', $types), 'Şema türü seçilmedi (varsayılan WebPage/Article + BreadcrumbList basılır).');
        $add('og', trim((string) ($f['og_title'] ?? '')) !== '' || $tl > 0, 'info', 'Open Graph başlığı hazır.', 'OG başlığı yok.');

        $weights = ['error' => 3, 'warn' => 2, 'info' => 1];
        $max = 0;
        $got = 0;

        foreach ($checks as $c) {
            $max += $weights[$c['level']];
            $got += $c['ok'] ? $weights[$c['level']] : 0;
        }

        return ['score' => $max > 0 ? (int) round($got / $max * 100) : 0, 'checks' => $checks];
    }

    public static function scoreLabel(?int $score): string
    {
        return match (true) {
            $score === null => 'Analiz yok',
            $score >= 80 => 'İyi',
            $score >= 50 => 'Orta',
            default => 'Zayıf',
        };
    }
}
