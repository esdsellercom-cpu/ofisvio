<?php

namespace App\Support;

use App\Enums\CompanyStatus;

/**
 * Aktivasyon akışının müşteriye gösterilen hâli.
 *
 * PROJEYE UYARLAMA — bu bölüm tasarımda YOKTU, eklendi. Gerekçesi:
 * Ofisvio'nun ürünü "bir masa kiralamak" değil, bir şirketin yasal adresinin
 * kurulmasıdır. O süreç backend'de zaten bir state machine olarak modellenmiş
 * (CompanyStatus). Müşterinin siteden görmek isteyeceği ilk şey "kaç adım,
 * ne kadar sürer, benden ne isteniyor" sorusunun cevabıdır.
 *
 * ADIMLAR ENUM'DAN TÜRETİLİR, elle yazılmaz. Böylece state machine değişince
 * site sessizce eskimez — yeni bir durum eklenip buraya eşlenmezse test kırılır.
 */
final class ActivationJourney
{
    /**
     * Müşteriye gösterilen adımlar ve karşılık geldikleri sistem durumları.
     * Bir adım birden fazla durumu kapsayabilir (KYC yükleme + inceleme).
     *
     * @return array<int, array{no: string, title: string, desc: string, statuses: array<CompanyStatus>}>
     */
    public static function steps(): array
    {
        return [
            [
                'no' => '01',
                'title' => 'Başvuru',
                'desc' => 'Şirket bilgilerinizi bırakın, size bir hesap açalım. Sözleşme öncesi ödeme yok.',
                'statuses' => [CompanyStatus::REGISTERED],
            ],
            [
                'no' => '02',
                'title' => 'Belge kontrolü',
                'desc' => 'Vergi levhası, sicil gazetesi, imza sirküleri ve kimlik. Panelden yüklersiniz, aynı iş günü içinde incelenir.',
                'statuses' => [CompanyStatus::KYC_PENDING, CompanyStatus::KYC_REVIEW, CompanyStatus::KYC_APPROVED],
            ],
            [
                'no' => '03',
                'title' => 'Sözleşme',
                'desc' => 'Kira sözleşmesi ve tescil muvafakatnamesi elektronik imzayla.',
                'statuses' => [CompanyStatus::CONTRACT_PENDING, CompanyStatus::CONTRACT_SIGNED],
            ],
            [
                'no' => '04',
                'title' => 'Ödeme',
                'desc' => 'İlk dönem bedeli ve depozito. Fatura anında panelinize düşer.',
                'statuses' => [
                    CompanyStatus::PAYMENT_PENDING,
                    CompanyStatus::PAYMENT_AUTHORIZED,
                    CompanyStatus::PAYMENT_CONFIRMED,
                ],
            ],
            [
                'no' => '05',
                'title' => 'Adres tahsisi',
                'desc' => 'Tescil adresiniz tanımlanır; ticaret sicilinde kullanabileceğiniz belgeler hazırlanır.',
                'statuses' => [CompanyStatus::ADDRESS_ASSIGNED],
            ],
            [
                'no' => '06',
                'title' => 'Aktif',
                'desc' => 'Evrak ve kargo karşılama başlar, toplantı odası krediniz tanımlanır.',
                'statuses' => [CompanyStatus::ACTIVE],
            ],
        ];
    }

    /**
     * Akışta yer alan durumlar. Testler bunu enum ile karşılaştırır:
     * state machine'e eklenen bir durum buraya eşlenmezse (ya da bilinçli
     * olarak istisna listesine alınmazsa) test kırılır.
     *
     * @return array<string>
     */
    public static function coveredStatuses(): array
    {
        $covered = [];

        foreach (self::steps() as $step) {
            foreach ($step['statuses'] as $status) {
                $covered[] = $status->value;
            }
        }

        return $covered;
    }

    /**
     * Müşteri akışında BİLİNÇLİ olarak gösterilmeyen durumlar: hepsi
     * "işler yolunda gitmedi" dallarıdır ve pazarlama sayfasında yeri yoktur.
     *
     * @return array<string>
     */
    public static function excludedStatuses(): array
    {
        return [
            CompanyStatus::SUSPENDED->value,
            CompanyStatus::TERMINATION_PENDING->value,
            CompanyStatus::TERMINATED->value,
        ];
    }
}
