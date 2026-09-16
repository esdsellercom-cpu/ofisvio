<?php

namespace App\Security\Scanners;

use App\Security\MalwareScanner;
use App\Security\ScanResult;

/**
 * Tarama YAPMAZ — her dosyayı temiz sayar. Yalnızca geliştirme ortamı için
 * (KYC_SCANNER=none). Üretimde bağlanması SecurityServiceProvider tarafından
 * reddedilir.
 */
final class NullScanner implements MalwareScanner
{
    public function scan(string $path): ScanResult
    {
        return ScanResult::clean();
    }
}
