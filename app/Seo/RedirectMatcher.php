<?php

namespace App\Seo;

/**
 * Benzer içerik eşleştirici (faz 54): silinen/taşınan bir adresin (slug, başlık, kategori, etiket, metin) mevcut
 * içeriklere (yazı, sayfa, hizmet, lokasyon) benzerliğini 0–100 puanlar. Deterministiktir, dış servis yoktur;
 * puan yalnız ÖNERİDİR — eşik kararı ve otomatik yönlendirme RedirectService'te (ayarlardaki eşiklerle) verilir.
 *
 * Bileşenler: slug benzerliği (.40), başlık (.25), aynı kategori (.10), etiket kesişimi (.10), ana konu metinde
 * geçiyor mu (.10), aynı tür (.05). Kaynakta olmayan bileşen ağırlıktan düşer (yalnız slug bilinen 404 için
 * slug + tür + metin puanlanır).
 */
final class RedirectMatcher
{
    private const STOPWORDS = ['ve', 'ile', 'icin', 'bir', 'nedir', 'nasil', 'neden', 'mi', 'mu', 'da', 'de', 'ki', 'bu', 'su', 'o', 'en', 'cok', 'daha', 'gibi', 'olan', 'olarak', 'uzerine', 'hakkinda', 'rehber', 'rehberi', 'the', 'and', 'of', 'to', 'in', 'for', 'ise', 'ya', 'veya', 'her', 'tum', 'yeni', 'eski', 'blog', 'yazi', 'sayfa', 'html', 'php', 'index'];

    private const SUFFIXES = ['lerinin', 'larinin', 'lerini', 'larini', 'lerin', 'larin', 'leri', 'lari', 'ler', 'lar', 'nin', 'nun', 'dan', 'den', 'tan', 'ten', 'in', 'un', 'da', 'de', 'ta', 'te', 'si', 'su', 'yi', 'yu', 'ye', 'ya', 'i', 'u', 'e', 'a'];

    /**
     * @param  array{path: string, title?: string|null, category?: string|null, tags?: array<int, string>|null, text?: string|null, kind?: string|null}  $source
     * @param  list<array{path: string, title: string, kind: string, category?: string|null, tags?: array<int, string>|null, text?: string|null}>  $candidates
     * @return list<array{path: string, title: string, kind: string, score: int, reasons: list<string>}>
     */
    public static function rank(array $source, array $candidates, int $limit = 5): array
    {
        $srcSlug = self::tokens(self::lastSegment($source['path']));
        $srcTitle = self::tokens((string) ($source['title'] ?? ''));
        $srcCategory = self::ascii((string) ($source['category'] ?? ''));
        $srcTags = array_values(array_unique(array_filter(array_map(fn ($t) => self::ascii((string) $t), (array) ($source['tags'] ?? [])))));
        $srcTopic = array_values(array_unique(array_merge($srcSlug, $srcTitle, $srcTags === [] ? [] : self::tokens(implode(' ', $srcTags)))));
        $srcKind = (string) ($source['kind'] ?? '');

        if ($srcSlug === [] && $srcTitle === []) {
            return [];
        }

        $ranked = [];

        foreach ($candidates as $candidate) {
            if ($candidate['path'] === $source['path']) {
                continue;
            }

            $components = [];
            $reasons = [];

            $slug = self::tokens(self::lastSegment($candidate['path']));
            $slugScore = max(self::dice($srcSlug, $slug), self::charSimilarity(self::lastSegment($source['path']), self::lastSegment($candidate['path'])) * 0.9);
            $components[] = [0.40, $slugScore];
            $reasons[] = 'adres %'.round($slugScore * 100);

            if ($srcTitle !== []) {
                $titleScore = self::dice($srcTitle, self::tokens($candidate['title']));
                $components[] = [0.25, $titleScore];
                $reasons[] = 'başlık %'.round($titleScore * 100);
            }

            if ($srcCategory !== '') {
                $same = $srcCategory === self::ascii((string) ($candidate['category'] ?? '')) ? 1.0 : 0.0;
                $components[] = [0.10, $same];

                if ($same > 0) {
                    $reasons[] = 'aynı kategori';
                }
            }

            if ($srcTags !== []) {
                $candidateTags = array_values(array_unique(array_filter(array_map(fn ($t) => self::ascii((string) $t), (array) ($candidate['tags'] ?? [])))));
                $tagScore = self::jaccard($srcTags, $candidateTags);
                $components[] = [0.10, $tagScore];

                if ($tagScore > 0) {
                    $reasons[] = 'etiket %'.round($tagScore * 100);
                }
            }

            $text = self::tokens(mb_substr((string) ($candidate['text'] ?? ''), 0, 4000).' '.$candidate['title']);
            $topicScore = $srcTopic === [] ? 0.0 : count(array_intersect($srcTopic, $text)) / count($srcTopic);
            $components[] = [0.10, $topicScore];

            if ($topicScore >= 0.5) {
                $reasons[] = 'konu metinde geçiyor';
            }

            if ($srcKind !== '') {
                $sameKind = $srcKind === $candidate['kind'] ? 1.0 : 0.0;
                $components[] = [0.05, $sameKind];
            }

            $weight = array_sum(array_map(fn (array $c) => $c[0], $components));
            $score = array_sum(array_map(fn (array $c) => $c[0] * $c[1], $components)) / $weight;
            $score = (int) round(max(0.0, min(1.0, $score)) * 100);

            if ($score <= 0) {
                continue;
            }

            $ranked[] = ['path' => $candidate['path'], 'title' => $candidate['title'], 'kind' => $candidate['kind'], 'score' => $score, 'reasons' => $reasons];
        }

        usort($ranked, fn (array $a, array $b) => [$b['score'], $a['path']] <=> [$a['score'], $b['path']]);

        return array_slice($ranked, 0, $limit);
    }

    /** Metni ASCII'ye indirger, ayırır, gereksiz/kısa kelimeleri atar, Türkçe çekim eklerini kaba biçimde soyar. @return list<string> */
    public static function tokens(string $text): array
    {
        $parts = preg_split('/[^a-z0-9]+/', self::ascii($text)) ?: [];
        $out = [];

        foreach ($parts as $part) {
            if (mb_strlen($part) < 3 || in_array($part, self::STOPWORDS, true) || ctype_digit($part)) {
                continue;
            }

            $out[] = self::stem($part);
        }

        return array_values(array_unique($out));
    }

    public static function ascii(string $text): string
    {
        $text = mb_strtolower(str_replace(['I', 'İ'], ['ı', 'i'], $text), 'UTF-8');

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtr($text, ['ı' => 'i', 'ş' => 's', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c', 'â' => 'a', 'î' => 'i', 'û' => 'u'])));
    }

    private static function stem(string $word): string
    {
        foreach (self::SUFFIXES as $suffix) {
            if (str_ends_with($word, $suffix) && mb_strlen($word) - mb_strlen($suffix) >= 4) {
                return substr($word, 0, -strlen($suffix));
            }
        }

        return $word;
    }

    private static function lastSegment(string $path): string
    {
        $parts = array_values(array_filter(explode('/', trim($path, '/'))));

        return $parts === [] ? '' : (string) end($parts);
    }

    /** @param  list<string>  $a  @param  list<string>  $b */
    private static function dice(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        return 2 * count(array_intersect($a, $b)) / (count($a) + count($b));
    }

    /** @param  list<string>  $a  @param  list<string>  $b */
    private static function jaccard(array $a, array $b): float
    {
        $union = array_unique(array_merge($a, $b));

        return $union === [] ? 0.0 : count(array_intersect($a, $b)) / count($union);
    }

    private static function charSimilarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        similar_text($a, $b, $percent);

        return $percent / 100;
    }
}
