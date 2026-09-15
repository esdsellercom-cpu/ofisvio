<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Lokasyonlar — REFERANS veri, ticari veri değil.
 *
 * Bir şubenin adı, adresi ve hangi çözümleri sunduğu operasyonel yapılandırmadır;
 * müşteri/rezervasyon/fatura gibi uydurulmuş ticari veri DEĞİLDİR. Bu ayrım
 * CLAUDE.md'deki "sahte ticari veri yasağı" ile tutarlıdır (bkz. RolePermissionSeeder
 * başındaki aynı gerekçe).
 *
 * updateOrCreate: elle düzenlenmiş bir şubenin verisi re-seed'de kaybolmasın diye
 * slug üzerinden eşleştirilir.
 */
class LocationSeeder extends Seeder
{
    private const LOCATIONS = [
        ['Levent 199', 'İstanbul', 'İstanbul Avrupa', 'Büyükdere Cd. No:199 · Levent M2\'ye 2 dk', 'amiral kat · 14. kat terası', ['Hazır Ofis', 'Coworking', 'Toplantı Odası'], 'Masa ₺4.900/ay'],
        ['Maslak Vadi', 'İstanbul', 'İstanbul Avrupa', 'Ahi Evran Cd. · Maslak M2 çıkışı', '3 kat · 2.400 m²', ['Hazır Ofis', 'Sanal Ofis', 'Toplantı Odası'], 'Masa ₺4.400/ay'],
        ['Nişantaşı Teşvikiye', 'İstanbul', 'İstanbul Avrupa', 'Teşvikiye Cd. · Osmanbey M2\'ye 6 dk', 'butik kat · 680 m²', ['Coworking', 'Sanal Ofis'], 'Masa ₺4.200/ay'],
        ['Beşiktaş Liman', 'İstanbul', 'İstanbul Avrupa', 'Barbaros Bulvarı · vapur iskelesi karşısı', 'deniz manzaralı · 5. kat', ['Coworking', 'Toplantı Odası'], 'Masa ₺3.900/ay'],
        ['Kadıköy Bahariye', 'İstanbul', 'İstanbul Anadolu', 'Bahariye Cd. · Kadıköy iskele 4 dk', 'tarihi bina · 4 kat', ['Coworking', 'Sanal Ofis', 'Etkinlik Alanı'], 'Masa ₺3.600/ay'],
        ['Ataşehir Finans', 'İstanbul', 'İstanbul Anadolu', 'Finans Merkezi · Ataşehir M4 bağlantılı', 'kule · 1.900 m²', ['Hazır Ofis', 'Toplantı Odası'], 'Ofis ₺16.500/ay'],
        ['Kozyatağı Park', 'İstanbul', 'İstanbul Anadolu', 'Saniye Ermutlu Sk. · Kozyatağı M4', 'bahçe katı · 1.200 m²', ['Hazır Ofis', 'Coworking'], 'Masa ₺3.700/ay'],
        ['Ümraniye Teknopark', 'İstanbul', 'İstanbul Anadolu', 'Alemdağ Cd. · Çakmak M5\'e 5 dk', 'yazılım ekipleri katı', ['Hazır Ofis', 'Sanal Ofis'], 'Ofis ₺13.900/ay'],
        ['Çankaya Kuleleri', 'Ankara', 'Ankara', 'Cinnah Cd. · Kızılay\'a 8 dk', '2 kat · 900 m²', ['Hazır Ofis', 'Sanal Ofis', 'Toplantı Odası'], 'Masa ₺3.200/ay'],
        ['Söğütözü Plaza', 'Ankara', 'Ankara', 'Dumlupınar Bulvarı · Söğütözü M2', 'kurumsal kat · 1.400 m²', ['Hazır Ofis', 'Toplantı Odası'], 'Ofis ₺11.800/ay'],
        ['Alsancak Kordon', 'İzmir', 'İzmir', 'Cumhuriyet Bulvarı · Kordon hattı', 'deniz cepheli · 3 kat', ['Coworking', 'Sanal Ofis', 'Etkinlik Alanı'], 'Masa ₺2.950/ay'],
        ['Bayraklı Tower', 'İzmir', 'İzmir', 'Yeni Kent Merkezi · Bayraklı İZBAN', 'kule · 18. kat', ['Hazır Ofis', 'Toplantı Odası'], 'Ofis ₺10.400/ay'],
        ['Nilüfer Ihlamur', 'Bursa', 'Bursa', 'Odunluk Mah. · İzmir Yolu aksı', 'bahçeli kampüs', ['Hazır Ofis', 'Coworking'], 'Masa ₺2.700/ay'],
        ['Lara Deniz', 'Antalya', 'Antalya', 'Barınaklar Bulvarı · Lara sahil', 'sezonluk · teras kat', ['Coworking', 'Sanal Ofis'], 'Masa ₺2.500/ay'],
    ];

    public function run(): void
    {
        foreach (self::LOCATIONS as $i => [$name, $city, $region, $address, $badge, $tags, $price]) {
            Location::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'city' => $city,
                    'region' => $region,
                    'address_line' => $address,
                    'badge' => $badge,
                    'tags' => $tags,
                    'price_from' => $price,
                    'is_active' => true,
                    'is_published' => true,
                    'sort_order' => $i + 1,
                ]
            );
        }

        // $command non-nullable: db:seed / Seeder::call() / $this->seed() set eder (bkz. RolePermissionSeeder).
        $this->command->info('Lokasyon seed tamamlandı: '.count(self::LOCATIONS).' lokasyon.');
    }
}
