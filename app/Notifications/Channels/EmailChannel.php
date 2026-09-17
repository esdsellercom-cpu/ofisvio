<?php

namespace App\Notifications\Channels;

use App\Models\NotificationLog;
use Illuminate\Contracts\Mail\Mailer;

/** Düz metin e-posta (Laravel mailer; üretimde SMTP env, geliştirmede log). */
class EmailChannel implements ChannelInterface
{
    public function __construct(private readonly Mailer $mailer) {}

    public function providerName(): string
    {
        return 'mail:'.(string) config('mail.default');
    }

    public function isAvailable(): bool
    {
        return (string) config('mail.default') !== 'array' || app()->runningUnitTests();
    }

    public function unavailableReason(): string
    {
        return 'E-posta sürücüsü tanımsız (MAIL_MAILER).';
    }

    public function send(NotificationLog $log): string
    {
        $subject = $log->subject ?: mb_substr(strtok($log->body, "\n") ?: 'Bildirim', 0, 160);
        $this->mailer->raw($log->body, fn ($m) => $m->to($log->recipient)->subject($subject));

        return '';
    }
}
