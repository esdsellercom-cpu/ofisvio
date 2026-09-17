<?php

namespace App\Jobs;

use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\Channels\ChannelRegistry;
use App\Notifications\InAppNotice;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Tek bildirim gönderimi (master prompt §19): günlük kaydını alır, kanal
 * adaptörünü çağırır, sonucu kayda yazar. Başarısızlıkta üstel geri çekilmeyle
 * yeniden dener (ayar: notifications.max_attempts); denemeler tükenince kayıt
 * 'failed' olur ve süper yöneticilere uygulama içi uyarı düşer. Kuyruk, iş
 * kaydından (rezervasyon) bağımsızdır — sağlayıcı arızası kaydı bozmaz.
 */
class SendNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $logId) {}

    public function tries(): int
    {
        return app(SettingsService::class)->int('notifications.max_attempts');
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 7200];
    }

    public function handle(ChannelRegistry $channels): void
    {
        $log = NotificationLog::query()->find($this->logId);

        if ($log === null || in_array($log->status, ['sent', 'delivered', 'skipped'], true)) {
            return;
        }

        $channel = $channels->for($log->channel);
        $log->attempt = $log->attempt + 1;

        if (! $channel->isAvailable()) {
            // Sağlayıcı kapalı: yeniden denemek anlamsız; atlandı olarak kapat, panelde görünür.
            $log->forceFill(['status' => 'skipped', 'provider' => $channel->providerName(), 'error_code' => 'provider_disabled', 'error' => $channel->unavailableReason(), 'failed_at' => Carbon::now()])->save();

            return;
        }

        try {
            $messageId = $channel->send($log);
            $log->forceFill(['status' => 'sent', 'provider' => $channel->providerName(), 'provider_message_id' => $messageId ?: null, 'sent_at' => Carbon::now(), 'error' => null, 'error_code' => null])->save();
        } catch (Throwable $e) {
            $log->forceFill(['provider' => $channel->providerName(), 'error' => mb_substr($e->getMessage(), 0, 300), 'error_code' => $this->code($e)])->save();

            throw $e; // kuyruk backoff ile yeniden dener; tükenince failed()
        }
    }

    public function failed(?Throwable $e): void
    {
        $log = NotificationLog::query()->find($this->logId);

        if ($log === null) {
            return;
        }

        $log->forceFill(['status' => 'failed', 'failed_at' => Carbon::now(), 'error' => mb_substr((string) $e?->getMessage(), 0, 300)])->save();

        // Yönetici uyarısı (uygulama içi): sonsuz döngü olmasın diye kural motoru değil, doğrudan süper yöneticiler.
        if (app(SettingsService::class)->bool('notifications.admin_alert_on_failure') && $log->event !== 'notification.failed') {
            $admins = User::query()->whereHas('userRoles', fn ($q) => $q->where('status', 'active')->whereNull('company_id')->whereNull('organization_id')->whereNull('location_id')->whereHas('role', fn ($r) => $r->where('name', 'super_admin')))->get();

            foreach ($admins as $admin) {
                $admin->notify(new InAppNotice('Bildirim gönderilemedi', "{$log->event} · {$log->channel} · ".$log->maskedRecipient()."\n".(string) $log->error, 'notification.failed'));
            }
        }
    }

    private function code(Throwable $e): string
    {
        return mb_substr((string) preg_replace('/[^a-z0-9_:]/i', '', strtok($e->getMessage(), ' ') ?: 'error'), 0, 40);
    }
}
