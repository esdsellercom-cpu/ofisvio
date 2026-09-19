<?php

namespace App\Integrations;

use Throwable;

/**
 * Çekirdek entegrasyonların (SMTP, S3) panelden girilen alanlarını çalışma zamanı config'ine uygular (faz 61b).
 * AppServiceProvider::boot her istekte çağırır; bu yüzden bağımlılığı yalnız IntegrationConfigRepository'dir —
 * ConnectionTester/MalwareScanner zinciri boot'ta çözülmez (composer `package:discover` .env'siz, production
 * varsayımıyla koşar; KYC_SCANNER denetimi orada patlamamalı). DB/anahtar sorunu uygulamayı düşürmez.
 */
class IntegrationRuntime
{
    public function __construct(private readonly IntegrationConfigRepository $repository) {}

    public function apply(): void
    {
        try {
            $mail = $this->repository->for('mail');
            $map = ['host' => 'mail.mailers.smtp.host', 'port' => 'mail.mailers.smtp.port', 'username' => 'mail.mailers.smtp.username', 'encryption' => 'mail.mailers.smtp.encryption', 'from_name' => 'mail.from.name', 'from_address' => 'mail.from.address'];

            foreach ($map as $field => $configKey) {
                if (isset($mail['config'][$field]) && trim((string) $mail['config'][$field]) !== '') {
                    config([$configKey => $field === 'port' ? (int) $mail['config'][$field] : ($field === 'encryption' && $mail['config'][$field] === 'none' ? null : $mail['config'][$field])]);
                }
            }

            if (isset($mail['secrets']['password'])) {
                config(['mail.mailers.smtp.password' => $mail['secrets']['password']]);
            }

            if ($mail['config'] !== [] && in_array((string) config('mail.default'), ['log', 'array'], true) && isset($mail['config']['host'])) {
                config(['mail.default' => 'smtp']); // panelden SMTP girildiyse log sürücüsü yerine gerçek gönderim
            }

            $storage = $this->repository->for('storage');
            $s3 = ['key' => 'filesystems.disks.s3.key', 'bucket' => 'filesystems.disks.s3.bucket', 'region' => 'filesystems.disks.s3.region', 'endpoint' => 'filesystems.disks.s3.endpoint', 'url' => 'filesystems.disks.s3.url'];

            foreach ($s3 as $field => $configKey) {
                if (isset($storage['config'][$field]) && trim((string) $storage['config'][$field]) !== '') {
                    config([$configKey => $storage['config'][$field]]);
                }
            }

            if (isset($storage['secrets']['secret'])) {
                config(['filesystems.disks.s3.secret' => $storage['secrets']['secret']]);
            }
        } catch (Throwable) {
            // Boot sırasında DB/anahtar sorunu uygulamayı düşürmez; panel durumu "yapılandırılmadı" gösterir.
        }
    }
}
