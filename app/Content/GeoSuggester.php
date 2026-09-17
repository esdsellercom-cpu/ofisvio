<?php

namespace App\Content;

use App\Models\Content;
use App\Services\SeoService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * GEO / AI arama önerileri (faz 48): dış model yok — öneriler içeriğin kendisinden türetilir (deterministik):
 * özet (özet/ilk paragraf), ana konu (odak kelime/başlık), varlıklar (büyük harfli ifadeler + sitedeki lokasyon/hizmet
 * adları), sorular ("## …?" başlıkları + kalıplar), SSS (başlık + ilk cevap paragrafı), kısa cevaplar, ilgili konular
 * (diğer yayındaki sayfalar), AI arama özeti, şema önerisi. Hiçbiri otomatik kaydedilmez: editör doldurur,
 * yönetici düzenler ve kaydeder.
 */
final class GeoSuggester
{
    /** GEO alan tanımları (form + kayıt). */
    public const FIELDS = ['summary' => 'Sayfa özeti', 'topic' => 'Ana konu', 'entities' => 'Varlıklar (entity)', 'questions' => 'Kullanıcı soruları', 'faq' => 'SSS önerileri', 'answers' => 'Kısa cevaplar', 'related' => 'İlgili konular', 'ai_summary' => 'AI arama özeti', 'schema' => 'Şema önerisi'];

    /**
     * @param  array<string, mixed>  $f  title, excerpt, body, focus_keyword, kind
     * @param  Collection<int, Content>  $others  aynı sitenin yayındaki diğer içerikleri
     * @param  array<int, string>  $knownEntities  lokasyon/hizmet adları
     * @return array<string, string>
     */
    public static function suggest(array $f, Collection $others, array $knownEntities = []): array
    {
        $title = trim((string) ($f['title'] ?? ''));
        $body = (string) ($f['body'] ?? '');
        $plain = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('/^#+\s.*$/m', '', $body))));
        $firstParagraph = trim((string) (preg_split('/\n\s*\n/', trim((string) preg_replace('/^#+\s.*$|^:::.*$/m', '', $body)))[0] ?? ''));
        $summary = trim((string) ($f['excerpt'] ?? '')) ?: Str::limit($firstParagraph, 280, '');
        $topic = trim((string) ($f['focus_keyword'] ?? '')) ?: $title;

        // Varlıklar: gövdede geçen bilinen lokasyon/hizmet adları + iki kelimelik büyük harfli ifadeler.
        $entities = [];
        foreach ($knownEntities as $name) {
            if ($name !== '' && mb_stripos($plain.' '.$title, $name) !== false) {
                $entities[] = $name;
            }
        }
        preg_match_all('/\b(\p{Lu}\p{Ll}+(?:\s+\p{Lu}\p{Ll}+){1,2})\b/u', $plain, $m);
        foreach (array_unique($m[1]) as $phrase) {
            if (! in_array($phrase, $entities, true) && count($entities) < 12) {
                $entities[] = $phrase;
            }
        }

        // Sorular: "## Soru?" başlıkları + odak kelime kalıpları.
        $pairs = SeoService::faqPairs($body);
        $questions = array_map(fn (array $p) => $p['q'], $pairs);
        if ($topic !== '') {
            foreach ([$topic.' nedir?', $topic.' nasıl çalışır?', $topic.' fiyatları ne kadar?', $topic.' için hangi belgeler gerekir?'] as $q) {
                if (count($questions) < 8) {
                    $questions[] = mb_strtoupper(mb_substr($q, 0, 1)).mb_substr($q, 1);
                }
            }
        }

        $faq = array_map(fn (array $p) => $p['q'].' | '.Str::limit($p['a'], 220, ''), $pairs);
        $answers = array_map(fn (array $p) => Str::limit(preg_split('/(?<=[.!?])\s+/u', $p['a'])[0] ?? $p['a'], 160, ''), $pairs);

        // İlgili konular: başlık kelimeleri/etiketleri kesişen diğer yayındaki içerikler.
        $words = array_filter(array_map(fn (string $w) => mb_strtolower($w), preg_split('/\P{L}+/u', $title.' '.$topic) ?: []), fn (string $w) => mb_strlen($w) >= 4);
        $related = $others->map(function (Content $c) use ($words) {
            $hay = mb_strtolower($c->title.' '.implode(' ', (array) ($c->tags ?? [])));
            $score = 0;
            foreach ($words as $w) {
                if (str_contains($hay, $w)) {
                    $score++;
                }
            }

            return ['score' => $score, 'line' => $c->title.' | '.$c->path()];
        })->filter(fn (array $r) => $r['score'] > 0)->sortByDesc('score')->take(6)->pluck('line')->all();

        $schema = match ((string) ($f['kind'] ?? 'page')) {
            'post' => 'Article + BreadcrumbList'.($pairs !== [] ? ' + FAQPage' : ''),
            default => 'WebPage + BreadcrumbList'.($pairs !== [] ? ' + FAQPage' : '').(preg_match('/hizmet|fiyat|paket/iu', $title) === 1 ? ' + Service' : ''),
        };

        return [
            'summary' => $summary,
            'topic' => $topic,
            'entities' => implode("\n", $entities),
            'questions' => implode("\n", array_unique($questions)),
            'faq' => implode("\n", $faq),
            'answers' => implode("\n", $answers),
            'related' => implode("\n", $related),
            'ai_summary' => Str::limit(trim($title.($summary !== '' ? ' — '.$summary : '')), 300, ''),
            'schema' => $schema,
        ];
    }

    /**
     * Form girdisi → kayıt biçimi (satırlar diziye; SSS "Soru | Cevap").
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        $lines = fn (mixed $raw): array => array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $raw) ?: []), fn (string $l) => $l !== ''));
        $faq = [];

        foreach ($lines($input['faq'] ?? '') as $line) {
            [$q, $a] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');

            if ($q !== '') {
                $faq[] = ['q' => $q, 'a' => $a];
            }
        }

        return [
            'summary' => trim((string) ($input['summary'] ?? '')),
            'topic' => trim((string) ($input['topic'] ?? '')),
            'entities' => $lines($input['entities'] ?? ''),
            'questions' => $lines($input['questions'] ?? ''),
            'faq' => $faq,
            'answers' => $lines($input['answers'] ?? ''),
            'related' => $lines($input['related'] ?? ''),
            'ai_summary' => trim((string) ($input['ai_summary'] ?? '')),
            'schema' => trim((string) ($input['schema'] ?? '')),
        ];
    }

    /**
     * Kayıt biçimi → form girdisi (satırlı metin).
     *
     * @param  array<string, mixed>|null  $geo
     * @return array<string, string>
     */
    public static function toForm(?array $geo): array
    {
        $geo ??= [];

        return [
            'summary' => (string) ($geo['summary'] ?? ''),
            'topic' => (string) ($geo['topic'] ?? ''),
            'entities' => implode("\n", (array) ($geo['entities'] ?? [])),
            'questions' => implode("\n", (array) ($geo['questions'] ?? [])),
            'faq' => implode("\n", array_map(fn ($p) => is_array($p) ? ($p['q'] ?? '').' | '.($p['a'] ?? '') : (string) $p, (array) ($geo['faq'] ?? []))),
            'answers' => implode("\n", (array) ($geo['answers'] ?? [])),
            'related' => implode("\n", (array) ($geo['related'] ?? [])),
            'ai_summary' => (string) ($geo['ai_summary'] ?? ''),
            'schema' => (string) ($geo['schema'] ?? ''),
        ];
    }
}
