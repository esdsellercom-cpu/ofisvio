<?php

namespace App\Security\Scanners;

use App\Security\MalwareScanner;
use App\Security\ScanResult;

/**
 * Tarama YAPILAMAZ — her dosya reddedilir (KYC_SCANNER=disabled).
 *
 * Paylaşımlı hostinglerde clamd kurulamaz. Seçenek "taramadan kabul et" DEĞİLDİR: bu sürücü her dosya için
 * "tarama yapılamadı" döndürür, KycService ve MediaService fail-closed davranarak yüklemeyi reddeder. Böylece
 * uygulama açılır (üretimde `none` sürücüsü kapta istisna atıp tüm siteyi 500'e düşürüyordu) ama taranmamış
 * hiçbir belge sisteme girmez. clamd kurulduğunda KYC_SCANNER=clamav yapılır.
 */
final class DisabledScanner implements MalwareScanner
{
    public function scan(string $path): ScanResult
    {
        return ScanResult::unavailable('KYC taraması yapılandırılmadı (KYC_SCANNER=disabled)');
    }
}
