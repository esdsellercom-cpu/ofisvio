<?php

namespace App\Providers;

use App\Security\MalwareScanner;
use App\Security\Scanners\ClamAvScanner;
use App\Security\Scanners\NullScanner;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * P0 güvenlik altyapısı bağlamaları (faz 5).
 *
 * MalwareScanner: config('ofisvio.kyc.scanner') seçer. 'none' yalnızca
 * geliştirme/test içindir — production ortamında bağlanması uygulamayı
 * açılışta durdurur; "tarayıcı yok ama yüklemeler geçiyor" durumu sessizce
 * oluşamaz.
 */
class SecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MalwareScanner::class, function ($app): MalwareScanner {
            $driver = (string) config('ofisvio.kyc.scanner', 'none');

            return match ($driver) {
                'clamav' => new ClamAvScanner(
                    (string) config('ofisvio.kyc.clamav.address'),
                    (int) config('ofisvio.kyc.clamav.timeout', 30),
                ),
                'none' => $this->nullScannerOrFail($app->environment()),
                default => throw new RuntimeException("Bilinmeyen KYC_SCANNER sürücüsü: {$driver}"),
            };
        });
    }

    private function nullScannerOrFail(string $environment): NullScanner
    {
        if ($environment === 'production') {
            throw new RuntimeException(
                'KYC_SCANNER=none production ortamında yasak: yüklenen belgeler taranmadan kabul edilirdi. '
                .'KYC_SCANNER=clamav ve CLAMAV_ADDRESS ayarlayın.'
            );
        }

        return new NullScanner;
    }
}
