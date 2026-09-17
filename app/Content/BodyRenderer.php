<?php

namespace App\Content;

use Illuminate\Support\Str;

/**
 * İçerik gövdesi (faz 48): Markdown + güvenli uzantılar. Ham HTML hiçbir yoldan girmez (html_input=strip);
 * tüm ek HTML burada kaçırılarak üretilir.
 *
 *  - Bloklar: satır başında `:::tür` … `:::` (hero, cta, features, faq, stats, gallery, contact, box, testimonials).
 *    İçerik "anahtar: değer" satırları + "- madde" listeleri; site/blocks/<tür>.blade.php ile çizilir.
 *  - Kısa kodlar (paragraf içinde): [youtube:VIDEO_ID] · [video:https://…mp4] · [button:Metin](/adres) · [embed:https://…]
 *    (embed yalnız izinli kökenler: YouTube, Vimeo, Google Maps).
 *  - Görsel öznitelikleri: ![alt](url "başlık"){left|right|center|full width=50%} → hizalama + genişlik + figure/caption.
 *  - Ayırıcı `---`, kod ``` ``` ```, tablo, alıntı, listeler markdown'dan.
 */
class BodyRenderer
{
    public const BLOCKS = ['hero' => 'Hero', 'cta' => 'CTA', 'features' => 'Özellikler', 'faq' => 'SSS', 'stats' => 'İstatistikler', 'gallery' => 'Galeri', 'contact' => 'İletişim', 'box' => 'İçerik kutusu', 'testimonials' => 'Referanslar'];

    public const EMBED_HOSTS = ['www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com', 'player.vimeo.com', 'vimeo.com', 'www.google.com', 'maps.google.com'];

    public function render(string $markdown): string
    {
        // Tarayıcı textarea'sı CRLF gönderir; blok/kısa kod kalıpları LF varsayar.
        [$markdown, $blocks] = $this->extractBlocks(str_replace("\r\n", "\n", $markdown));

        $html = (string) Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);

        $html = $this->imageAttributes($html);
        $html = $this->shortcodes($html);

        foreach ($blocks as $token => $blockHtml) {
            $html = str_replace('<p>'.$token.'</p>', $blockHtml, $html);
            $html = str_replace($token, $blockHtml, $html);
        }

        return $html;
    }

    /**
     * Bloklar markdown'dan çıkarılır (yer tutucu satır), ayrı çizilir, sonra HTML'e geri konur.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function extractBlocks(string $markdown): array
    {
        $blocks = [];
        $i = 0;
        $out = (string) preg_replace_callback('/^:::([a-z]+)[ \t]*\n(.*?)^:::[ \t]*$/ms', function (array $m) use (&$blocks, &$i) {
            $type = $m[1];

            if (! isset(self::BLOCKS[$type])) {
                return $m[0]; // bilinmeyen blok olduğu gibi kalır (metin olarak görünür)
            }

            $token = 'OFISVIOBLOCK'.$i++.'X';
            $blocks[$token] = view('site.blocks.'.$type, ['data' => self::parseBlock($m[2]), 'type' => $type])->render();

            return $token;
        }, $markdown);

        return [$out, $blocks];
    }

    /**
     * "anahtar: değer" ve "- madde" (madde "Başlık | Açıklama | /adres" olabilir).
     *
     * @return array{fields: array<string, string>, items: array<int, array{title: string, text: string, link: string}>}
     */
    public static function parseBlock(string $body): array
    {
        $fields = [];
        $items = [];

        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            $line = rtrim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^-\s+(.+)$/', $line, $m) === 1) {
                $parts = array_map('trim', explode('|', $m[1]));
                $items[] = ['title' => $parts[0], 'text' => $parts[1] ?? '', 'link' => $parts[2] ?? ''];

                continue;
            }

            if (preg_match('/^([a-z_]+)\s*:\s*(.*)$/', $line, $m) === 1) {
                $fields[$m[1]] = trim($m[2]);
            }
        }

        return ['fields' => $fields, 'items' => $items];
    }

    /** ![alt](src "title"){left width=40%} → figure + hizalama; markdown img etiketi sonrası süslü parantez. */
    private function imageAttributes(string $html): string
    {
        return (string) preg_replace_callback('/<img([^>]*)>\{([^}]*)\}/', function (array $m) {
            $attrs = $m[2];
            $classes = ['content-img'];

            foreach (['left', 'right', 'center', 'full'] as $align) {
                if (preg_match('/\b'.$align.'\b/', $attrs) === 1) {
                    $classes[] = 'img-'.$align;
                }
            }

            $style = '';

            if (preg_match('/width=(\d{1,3})%/', $attrs, $w) === 1) {
                $style = ' style="width:'.min(100, max(10, (int) $w[1])).'%"';
            }

            $caption = preg_match('/ title="([^"]*)"/', $m[1], $t) === 1 ? $t[1] : '';
            $img = '<img'.$m[1].' class="'.implode(' ', $classes).'" loading="lazy" decoding="async">';

            return '<figure class="'.implode(' ', $classes).'"'.$style.'>'.$img.($caption !== '' ? '<figcaption>'.$caption.'</figcaption>' : '').'</figure>';
        }, $html);
    }

    private function shortcodes(string $html): string
    {
        // [youtube:ID] — youtube-nocookie gömme; ID yalnız güvenli karakterler.
        $html = (string) preg_replace_callback('/\[youtube:([A-Za-z0-9_-]{6,20})\]/', fn (array $m) => '<div class="embed embed--video"><iframe src="https://www.youtube-nocookie.com/embed/'.$m[1].'" title="YouTube video" loading="lazy" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>', $html);

        // [video:https://…mp4]
        $html = (string) preg_replace_callback('/\[video:(https?:\/\/[^\s\]]+\.(?:mp4|webm))\]/i', fn (array $m) => '<div class="embed embed--video"><video controls preload="metadata" src="'.e($m[1]).'"></video></div>', $html);

        // [embed:URL] — yalnız izinli kökenler.
        $html = (string) preg_replace_callback('/\[embed:(https:\/\/[^\s\]]+)\]/', function (array $m) {
            $host = (string) parse_url($m[1], PHP_URL_HOST);

            if (! in_array($host, self::EMBED_HOSTS, true)) {
                return '<span class="muted">[embed: izin verilmeyen kaynak]</span>';
            }

            return '<div class="embed"><iframe src="'.e($m[1]).'" loading="lazy" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>';
        }, $html);

        // [button:Metin](/adres) — markdown bağlantıya çevirmiş olabilir: <a href="/adres">[button:Metin]</a> ya da ham.
        $html = (string) preg_replace('/<a href="([^"]+)">\[?button:([^<\]]+)\]?<\/a>/', '<a href="$1" class="btn btn--brand btn--pill content-cta">$2</a>', $html);
        $html = (string) preg_replace_callback('/\[button:([^\]]+)\]\(([^)\s]+)\)/', fn (array $m) => '<a href="'.e($m[2]).'" class="btn btn--brand btn--pill content-cta">'.e($m[1]).'</a>', $html);

        return $html;
    }
}
