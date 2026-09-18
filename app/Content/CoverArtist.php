<?php

namespace App\Content;

use GdImage;
use RuntimeException;

/**
 * Marka diline uygun kapak görseli üretici (faz 58): stok fotoğraf yerine tasarım sistemi paletiyle (kum zemin, orman yeşili,
 * açık yeşil, altın vurgu) temaya göre çizilen 1200×750 PNG. Medya kütüphanesine gerçek Media kaydı olarak yüklenir;
 * admin dilediğinde fotoğrafla değiştirir. Metin çizilmez (font bağımlılığı yok); görsel bilgi alt metninde.
 */
final class CoverArtist
{
    public const WIDTH = 1200;

    public const HEIGHT = 750;

    public const THEMES = ['virtual', 'office', 'meeting', 'cowork', 'city', 'growth', 'remote', 'legal'];

    /** @return array{bg: int, bg2: int, ink: int, brand: int, light: int, wash: int, line: int, accent: int, paper: int} */
    private static function palette(GdImage $im): array
    {
        return [
            'bg' => (int) imagecolorallocate($im, 0xF5, 0xF1, 0xE8),
            'bg2' => (int) imagecolorallocate($im, 0xEA, 0xF3, 0xED),
            'ink' => (int) imagecolorallocate($im, 0x1E, 0x1C, 0x18),
            'brand' => (int) imagecolorallocate($im, 0x1F, 0x5B, 0x45),
            'light' => (int) imagecolorallocate($im, 0x9E, 0xCB, 0xB4),
            'wash' => (int) imagecolorallocate($im, 0xEA, 0xF3, 0xED),
            'line' => (int) imagecolorallocate($im, 0xE3, 0xDE, 0xD2),
            'accent' => (int) imagecolorallocate($im, 0xC9, 0xA2, 0x4A),
            'paper' => (int) imagecolorallocate($im, 0xFF, 0xFD, 0xF8),
        ];
    }

    /** PNG ikili verisi. Tema listede yoksa 'office'. */
    public static function png(string $theme, int $seed = 0): string
    {
        $theme = in_array($theme, self::THEMES, true) ? $theme : 'office';
        $im = imagecreatetruecolor(self::WIDTH, self::HEIGHT);

        if ($im === false) {
            throw new RuntimeException('GD görsel oluşturamadı.');
        }

        imageantialias($im, true);
        $c = self::palette($im);
        $w = self::WIDTH;
        $h = self::HEIGHT;

        // Zemin: dikey geçiş + ince ızgara.
        for ($y = 0; $y < $h; $y++) {
            $t = $y / $h;
            $col = imagecolorallocate($im, (int) (0xF5 + (0xEA - 0xF5) * $t), (int) (0xF1 + (0xF3 - 0xF1) * $t), (int) (0xE8 + (0xED - 0xE8) * $t));
            imageline($im, 0, $y, $w, $y, (int) $col);
        }

        for ($x = 0; $x < $w; $x += 40) {
            imageline($im, $x, 0, $x, $h, $c['line']);
        }

        for ($y = 0; $y < $h; $y += 40) {
            imageline($im, 0, $y, $w, $y, $c['line']);
        }

        // Yumuşak daire (tohuma göre konum).
        $cx = 860 + ($seed * 37) % 160;
        $cy = 180 + ($seed * 53) % 120;
        imagefilledellipse($im, $cx, $cy, 320, 320, $c['light']);
        self::alphaCircle($im, $cx, $cy, 320, 0x9E, 0xCB, 0xB4, 90);

        // Zemin çizgisi.
        imagesetthickness($im, 4);
        imageline($im, 80, 600, $w - 80, 600, $c['ink']);

        match ($theme) {
            'virtual' => self::virtual($im, $c),
            'meeting' => self::meeting($im, $c),
            'cowork' => self::cowork($im, $c),
            'city' => self::city($im, $c),
            'growth' => self::growth($im, $c),
            'remote' => self::remote($im, $c),
            'legal' => self::legal($im, $c),
            default => self::office($im, $c),
        };

        ob_start();
        imagepng($im, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        return $png;
    }

    /** @param  array<string, int>  $c */
    private static function rect(GdImage $im, int $x, int $y, int $w, int $h, int $fill, ?int $stroke, array $c): void
    {
        imagefilledrectangle($im, $x, $y, $x + $w, $y + $h, $fill);

        if ($stroke !== null) {
            imagesetthickness($im, 4);
            imagerectangle($im, $x, $y, $x + $w, $y + $h, $stroke);
        }
    }

    private static function alphaCircle(GdImage $im, int $cx, int $cy, int $d, int $r, int $g, int $b, int $alpha): void
    {
        $col = imagecolorallocatealpha($im, $r, $g, $b, $alpha);
        imagefilledellipse($im, $cx, $cy, $d, $d, (int) $col);
    }

    /** @param  array<string, int>  $c */
    private static function windows(GdImage $im, int $x, int $y, int $cols, int $rows, int $size, int $gap, array $c, int $highlightEvery = 4): void
    {
        $n = 0;

        for ($r = 0; $r < $rows; $r++) {
            for ($k = 0; $k < $cols; $k++) {
                $fill = ($n % $highlightEvery === 2) ? $c['light'] : $c['wash'];
                self::rect($im, $x + $k * ($size + $gap), $y + $r * ($size + $gap), $size, $size, $fill, $c['ink'], $c);
                $n++;
            }
        }
    }

    /** @param  array<string, int>  $c */
    private static function office(GdImage $im, array $c): void
    {
        self::rect($im, 150, 200, 420, 400, $c['paper'], $c['ink'], $c);
        self::rect($im, 150, 200, 420, 70, $c['brand'], null, $c);
        self::windows($im, 195, 310, 3, 2, 80, 40, $c);
        self::rect($im, 320, 520, 80, 80, $c['brand'], null, $c);
        // masa + ekran
        self::rect($im, 660, 470, 400, 16, $c['ink'], null, $c);
        self::rect($im, 690, 486, 12, 114, $c['ink'], null, $c);
        self::rect($im, 1018, 486, 12, 114, $c['ink'], null, $c);
        self::rect($im, 800, 380, 130, 80, $c['paper'], $c['ink'], $c);
        self::rect($im, 812, 392, 106, 56, $c['brand'], null, $c);
        self::rect($im, 990, 430, 60, 40, $c['accent'], null, $c);
        imagefilledellipse($im, 720, 440, 60, 60, $c['light']);
        self::rect($im, 712, 440, 16, 30, $c['brand'], null, $c);
    }

    /** @param  array<string, int>  $c */
    private static function virtual(GdImage $im, array $c): void
    {
        // zarf
        self::rect($im, 180, 260, 520, 340, $c['paper'], $c['ink'], $c);
        imagesetthickness($im, 4);
        imageline($im, 180, 280, 440, 460, $c['ink']);
        imageline($im, 700, 280, 440, 460, $c['ink']);
        self::rect($im, 240, 200, 400, 220, $c['wash'], $c['ink'], $c);
        self::rect($im, 270, 240, 200, 14, $c['brand'], null, $c);
        self::rect($im, 270, 275, 300, 10, $c['light'], null, $c);
        self::rect($im, 270, 300, 260, 10, $c['light'], null, $c);
        // konum iğnesi
        self::pin($im, 900, 330, $c['brand'], $c);
    }

    /** @param  array<string, int>  $c */
    private static function pin(GdImage $im, int $x, int $y, int $fill, array $c): void
    {
        imagefilledellipse($im, $x, $y, 180, 180, $fill);
        imagefilledpolygon($im, [$x - 70, $y + 50, $x + 70, $y + 50, $x, $y + 200], $fill);
        imagefilledellipse($im, $x, $y, 70, 70, $c['paper']);
        imagefilledellipse($im, $x, $y, 26, 26, $c['accent']);
    }

    /** @param  array<string, int>  $c */
    private static function meeting(GdImage $im, array $c): void
    {
        // ekran
        self::rect($im, 440, 120, 320, 180, $c['paper'], $c['ink'], $c);
        self::rect($im, 458, 138, 284, 144, $c['brand'], null, $c);
        self::rect($im, 490, 175, 120, 14, $c['light'], null, $c);
        self::rect($im, 490, 205, 180, 10, $c['wash'], null, $c);
        // masa
        imagefilledellipse($im, 600, 520, 640, 200, $c['paper']);
        imagesetthickness($im, 4);
        imageellipse($im, 600, 520, 640, 200, $c['ink']);
        // sandalyeler
        foreach ([[250, 360], [450, 340], [750, 340], [950, 360], [280, 600], [920, 600]] as [$x, $y]) {
            self::rect($im, $x, $y, 60, 70, $c['wash'], $c['ink'], $c);
        }
        imagefilledellipse($im, 720, 540, 30, 30, $c['accent']);
    }

    /** @param  array<string, int>  $c */
    private static function cowork(GdImage $im, array $c): void
    {
        self::rect($im, 140, 470, 920, 16, $c['ink'], null, $c);

        foreach ([[300, $c['brand']], [600, $c['accent']], [900, $c['brand']]] as [$x, $fill]) {
            imagefilledellipse($im, $x, 330, 70, 70, $fill);
            imagefilledpolygon($im, [$x - 70, 470, $x + 70, 470, $x + 60, 400, $x - 60, 400], $fill);
            self::rect($im, $x - 60, 400, 120, 70, $c['paper'], $c['ink'], $c);
            self::rect($im, $x - 44, 414, 88, 40, $c['light'], null, $c);
        }

        imagefilledellipse($im, 180, 180, 120, 120, $c['wash']);
        imagesetthickness($im, 8);
        imageline($im, 150, 180, 210, 180, $c['brand']);
        imageline($im, 180, 150, 180, 210, $c['brand']);
    }

    /** @param  array<string, int>  $c */
    private static function city(GdImage $im, array $c): void
    {
        self::rect($im, 130, 360, 170, 240, $c['wash'], $c['ink'], $c);
        self::rect($im, 340, 260, 220, 340, $c['paper'], $c['ink'], $c);
        self::rect($im, 600, 400, 160, 200, $c['wash'], $c['ink'], $c);
        self::rect($im, 800, 330, 110, 270, $c['paper'], $c['ink'], $c);
        self::windows($im, 370, 300, 3, 3, 46, 24, $c);
        self::windows($im, 160, 400, 2, 2, 40, 30, $c, 3);
        self::windows($im, 625, 430, 2, 2, 40, 30, $c, 3);
        self::rect($im, 420, 520, 60, 80, $c['brand'], null, $c);
        self::pin($im, 450, 150, $c['brand'], $c);
    }

    /** @param  array<string, int>  $c */
    private static function growth(GdImage $im, array $c): void
    {
        foreach ([[180, 480, $c['light']], [340, 400, $c['brand']], [500, 320, $c['light']], [660, 220, $c['brand']]] as [$x, $y, $fill]) {
            self::rect($im, $x, $y, 90, 600 - $y, $fill, null, $c);
        }

        imagesetthickness($im, 6);
        imageline($im, 225, 440, 385, 360, $c['ink']);
        imageline($im, 385, 360, 545, 280, $c['ink']);
        imageline($im, 545, 280, 705, 180, $c['ink']);
        imagefilledpolygon($im, [705, 180, 660, 190, 700, 230], $c['ink']);
        self::pin($im, 940, 360, $c['accent'], $c);
    }

    /** @param  array<string, int>  $c */
    private static function remote(GdImage $im, array $c): void
    {
        // dizüstü
        self::rect($im, 300, 260, 460, 270, $c['paper'], $c['ink'], $c);
        self::rect($im, 322, 282, 416, 226, $c['brand'], null, $c);
        self::rect($im, 360, 330, 200, 14, $c['light'], null, $c);
        self::rect($im, 360, 364, 300, 10, $c['wash'], null, $c);
        self::rect($im, 360, 392, 240, 10, $c['wash'], null, $c);
        self::rect($im, 240, 530, 580, 40, $c['ink'], null, $c);
        // fincan
        self::rect($im, 900, 470, 90, 100, $c['paper'], $c['ink'], $c);
        imagesetthickness($im, 4);
        imagearc($im, 1000, 520, 60, 60, 270, 90, $c['ink']);
        imagefilledellipse($im, 945, 470, 90, 26, $c['accent']);
    }

    /** @param  array<string, int>  $c */
    private static function legal(GdImage $im, array $c): void
    {
        self::rect($im, 320, 160, 420, 440, $c['paper'], $c['ink'], $c);
        self::rect($im, 320, 160, 420, 60, $c['brand'], null, $c);
        self::rect($im, 360, 250, 240, 14, $c['ink'], null, $c);

        foreach ([300, 330, 360, 390, 420] as $y) {
            self::rect($im, 360, $y, 340 - (($y / 30) % 3) * 40, 10, $c['light'], null, $c);
        }

        imagefilledellipse($im, 880, 420, 200, 200, $c['accent']);
        imagefilledellipse($im, 880, 420, 130, 130, $c['paper']);
        imagesetthickness($im, 12);
        imageline($im, 840, 420, 870, 450, $c['brand']);
        imageline($im, 870, 450, 930, 385, $c['brand']);
    }
}
