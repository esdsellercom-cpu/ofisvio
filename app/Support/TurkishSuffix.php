<?php

namespace App\Support;

/**
 * Türkçe ek üretimi (özel adlar için kesme işaretli): "Konya" → "Konya'da", "İzmir" → "İzmir'de", "Kayseri" → "Kayseri'de".
 * Büyük ünlü uyumu + ünsüz sertleşmesi (f s t k ç ş h p → -ta/-te). Şehir adlarında yeterli; genel çekim motoru değildir.
 */
final class TurkishSuffix
{
    private const BACK_VOWELS = ['a', 'ı', 'o', 'u', 'â', 'û'];

    private const FRONT_VOWELS = ['e', 'i', 'ö', 'ü', 'î'];

    private const HARD_CONSONANTS = ['f', 's', 't', 'k', 'ç', 'ş', 'h', 'p'];

    /** Bulunma durumu: -da/-de/-ta/-te. Boş ad boş döner. */
    public static function locative(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            return '';
        }

        $lower = mb_strtolower(str_replace('I', 'ı', $name), 'UTF-8');
        $last = mb_substr($lower, -1, 1, 'UTF-8');
        $vowel = 'a';

        for ($i = mb_strlen($lower, 'UTF-8') - 1; $i >= 0; $i--) {
            $ch = mb_substr($lower, $i, 1, 'UTF-8');

            if (in_array($ch, self::BACK_VOWELS, true)) {
                break;
            }

            if (in_array($ch, self::FRONT_VOWELS, true)) {
                $vowel = 'e';
                break;
            }
        }

        $consonant = in_array($last, self::HARD_CONSONANTS, true) ? 't' : 'd';

        return $name."'".$consonant.$vowel;
    }
}
