<?php

namespace App\Console\Commands;

use App\Models\NotificationRecipient;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Bildirim merkezi ilk kurulumu (master prompt §12): varsayılan kural seti (yalnız
 * hiç kural yoksa) + env'den ilk WhatsApp alıcısı (OFISVIO_BOOKING_NOTIFY_WHATSAPP,
 * grup booking_managers). Telefon kodda/seed'de YAZILMAZ; sonrası panelden yönetilir.
 * Idempotent: aynı numara ikinci kez eklenmez.
 */
class BootstrapNotificationsCommand extends Command
{
    protected $signature = 'ofisvio:bootstrap-notifications';

    protected $description = 'Varsayılan bildirim kurallarını ve env\'deki ilk WhatsApp alıcısını kurar.';

    public function handle(NotificationService $notifications): int
    {
        $rules = $notifications->seedDefaultRules();
        $this->info($rules > 0 ? "{$rules} varsayılan kural yazıldı." : 'Kurallar zaten var; dokunulmadı.');

        $phone = trim((string) config('ofisvio.notifications.booking_whatsapp'));

        if ($phone === '') {
            $this->warn('OFISVIO_BOOKING_NOTIFY_WHATSAPP tanımsız; WhatsApp alıcısı eklenmedi (panelden ekleyin).');

            return self::SUCCESS;
        }

        if (preg_match('/^\+[1-9][0-9]{7,14}$/', $phone) !== 1) {
            $this->error('OFISVIO_BOOKING_NOTIFY_WHATSAPP E.164 biçiminde olmalı (+90…).');

            return self::FAILURE;
        }

        if (NotificationRecipient::query()->where('channel', 'whatsapp')->where('address', $phone)->exists()) {
            $this->info('WhatsApp alıcısı zaten kayıtlı.');

            return self::SUCCESS;
        }

        NotificationRecipient::query()->create(['name' => 'Rezervasyon yönetimi (WhatsApp)', 'channel' => 'whatsapp', 'address' => $phone, 'group' => 'booking_managers', 'is_active' => true]);
        $this->info('WhatsApp alıcısı eklendi: '.substr($phone, 0, 4).'***'.substr($phone, -2));

        return self::SUCCESS;
    }
}
