<?php

namespace App\Site;

use App\Support\TurkishSuffix;

/**
 * Marka illüstrasyon seti (faz 53): medya kütüphanesinde görsel yokken vitrindeki boş görsel alanlarını dolduran,
 * tasarım diline uygun SVG'ler (`public/images/illustrations`). Gerçek görsel yüklenince (`Media`) önceliği o alır;
 * burada ticari veri yok — yalnız çizim + alt metni. Yeni görsel = SET'e satır + dosya.
 */
final class Illustrations
{
    private const DIR = 'images/illustrations';

    /** anahtar => [dosya, genişlik, yükseklik, alt metni (konu)] */
    public const SET = [
        'hero' => ['hero-office', 800, 1000, 'Modern ofis binası ve hazır çalışma masası'],
        'location' => ['location', 800, 600, 'Şehir merkezinde, ulaşımı kolay ofis binası'],
        'franchise' => ['franchise', 800, 600, 'Birlikte büyüyen şube ağı ve iş ortaklığı'],
        'blog' => ['blog', 800, 600, 'Çalışma kültürü ve ofis rehberi yazısı'],
        'sanal-ofis' => ['sanal-ofis', 800, 600, 'Sanal ofis: yasal adres, posta ve evrak yönetimi'],
        'hazir-ofis' => ['hazir-ofis', 800, 600, 'Hazır ofis: mobilyalı, hemen taşınılabilir özel ofis'],
        'coworking' => ['coworking', 800, 600, 'Coworking: paylaşımlı çalışma alanı ve esnek masalar'],
        'gun-gecisi' => ['gun-gecisi', 800, 600, 'Günlük geçiş: tek günlük çalışma alanı erişimi'],
        'toplanti-odasi' => ['toplanti-odasi', 800, 600, 'Toplantı odası: ekranlı, saatlik rezervasyonlu masa'],
        'etkinlik-alani' => ['etkinlik-alani', 800, 600, 'Etkinlik alanı: sahne ve oturma düzeniyle salon'],
    ];

    /** Hizmet slug/adından illüstrasyon anahtarı (anahtar kelime eşlemesi; eşleşmezse hazır ofis). */
    private const SERVICE_HINTS = [
        'sanal' => 'sanal-ofis', 'virtual' => 'sanal-ofis', 'adres' => 'sanal-ofis',
        'cowork' => 'coworking', 'paylas' => 'coworking', 'ortak' => 'coworking', 'masa' => 'coworking',
        'gun' => 'gun-gecisi', 'day' => 'gun-gecisi', 'gecis' => 'gun-gecisi',
        'toplanti' => 'toplanti-odasi', 'meeting' => 'toplanti-odasi', 'oda' => 'toplanti-odasi',
        'etkinlik' => 'etkinlik-alani', 'event' => 'etkinlik-alani', 'egitim' => 'etkinlik-alani', 'seminer' => 'etkinlik-alani',
        'hazir' => 'hazir-ofis', 'ofis' => 'hazir-ofis', 'ozel' => 'hazir-ofis',
    ];

    public static function url(string $key): string
    {
        return asset(self::DIR.'/'.(self::SET[$key][0] ?? self::SET['hazir-ofis'][0]).'.svg');
    }

    /** @return array{0: string, 1: int, 2: int, 3: string} */
    public static function meta(string $key): array
    {
        return self::SET[$key] ?? self::SET['hazir-ofis'];
    }

    /**
     * Alt metni: konu + (tek lokasyon modunda) şehir, ör. "Konya'da sanal ofis: … illüstrasyonu".
     * Şehir yalnız veritabanındaki lokasyondan gelir; uydurma yer adı yok.
     */
    public static function alt(string $key, ?string $city = null, ?string $subject = null): string
    {
        $topic = $subject ?? self::meta($key)[3];
        $city = trim((string) $city);

        if ($city !== '') {
            // Set'teki genel konu küçük harfle bağlanır; özel ad taşıyan konu (şube/hizmet adı) olduğu gibi kalır.
            $topic = TurkishSuffix::locative($city).' '.($subject === null ? mb_strtolower(mb_substr($topic, 0, 1, 'UTF-8'), 'UTF-8').mb_substr($topic, 1, null, 'UTF-8') : $topic);
        }

        return $topic.' — illüstrasyon';
    }

    /** Hizmet için anahtar: slug ve ad taranır, ilk eşleşen ipucu kazanır. */
    public static function forService(string $slug, string $name = ''): string
    {
        $haystack = self::ascii($slug.' '.$name);

        foreach (self::SERVICE_HINTS as $hint => $key) {
            if (str_contains($haystack, $hint)) {
                return $key;
            }
        }

        return 'hazir-ofis';
    }

    private static function ascii(string $text): string
    {
        return mb_strtolower(strtr($text, ['ı' => 'i', 'İ' => 'i', 'ş' => 's', 'Ş' => 's', 'ğ' => 'g', 'Ğ' => 'g', 'ü' => 'u', 'Ü' => 'u', 'ö' => 'o', 'Ö' => 'o', 'ç' => 'c', 'Ç' => 'c']), 'UTF-8');
    }
}
