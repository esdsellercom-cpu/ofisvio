<?php

namespace App\Listeners;

use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

/**
 * Giriş geçmişi (audit): Auth/Fortify olaylarını login_events'e yazar. Otomatik keşifle
 * bağlanır (handle metodu union tipiyle birden çok olayı dinler — EventServiceProvider'a elle
 * eklenmez). IP/UA HTTP katmanından; konsol/kuyruktan tetiklenen olaylarda boş kalır.
 */
class RecordLoginEvent
{
    public function __construct(private readonly Request $request) {}

    public function handle(Login|Failed|Logout|Lockout|PasswordReset|OtherDeviceLogout|ValidTwoFactorAuthenticationCodeProvided $event): void
    {
        [$type, $user, $email] = match (true) {
            $event instanceof Login => ['login', $event->user, null],
            $event instanceof Failed => ['failed', $event->user, (string) ($event->credentials['email'] ?? '')],
            $event instanceof Logout => ['logout', $event->user, null],
            $event instanceof Lockout => ['lockout', null, (string) $event->request->input('email', '')],
            $event instanceof PasswordReset => ['password_reset', $event->user, null],
            $event instanceof OtherDeviceLogout => ['other_devices_logout', $event->user, null],
            default => ['two_factor', $event->user, null],
        };

        if (! ($user instanceof User) && $user !== null) {
            $user = null;
        }

        LoginEvent::query()->create([
            'user_id' => $user?->id,
            'email' => mb_substr((string) ($email ?: $user?->email ?: ''), 0, 190) ?: null,
            'event' => $type,
            'ip' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 255) ?: null,
            'created_at' => now(),
        ]);
    }
}
