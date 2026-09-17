<?php

namespace App\Services;

use App\Jobs\SendNotification;
use App\Models\Location;
use App\Models\NotificationLog;
use App\Models\NotificationRecipient;
use App\Models\NotificationRule;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\NotificationEvents;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Bildirim merkezi (master prompt §16–19).
 *
 *   dispatch(olay, yük) → kurallar (olay × kanal × grup, etkin) → alıcılar (grup +
 *   lokasyon süzgeci; 'customer' grubu yükten) → şablon (DB, yoksa teknik varsayılan)
 *   → notification_logs (queued) → kuyruk (SendNotification) → kanal → sağlayıcı.
 *
 * dispatch DB commit'inden SONRA çağrılır (DB::afterCommit); sağlayıcı arızası iş
 * kaydını asla bozmaz (§19). Alıcı adresleri yalnız DB'de; kodda telefon yok.
 */
class NotificationService
{
    public const CACHE_KEY = 'notifications:rules';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
    ) {}

    /**
     * Olayı bildirim kurallarından geçirip kuyruğa alır; oluşturulan günlük kayıtlarını döndürür.
     *
     * @param  array<string, string|int|float|null>  $payload  yer tutucular + customer_email/customer_phone/customer_user_id
     * @return Collection<int, NotificationLog>
     */
    public function dispatch(string $event, array $payload, ?int $locationId = null, ?string $entityType = null, ?int $entityId = null): Collection
    {
        $def = NotificationEvents::definition($event);
        $payload['brand'] = $payload['brand'] ?? $this->settings->string('whatsapp.sender_label');
        $logs = new Collection;

        foreach ($this->rules($event) as $rule) {
            foreach ($this->recipientsFor($rule['group'], $rule['channel'], $locationId, $payload) as $recipient) {
                [$subject, $body, $templateName] = $this->render($event, $rule['channel'], $payload);

                $log = NotificationLog::create([
                    'event' => $event,
                    'channel' => $rule['channel'],
                    'recipient' => $recipient['address'],
                    'recipient_id' => $recipient['id'],
                    'provider' => null,
                    'template' => $templateName,
                    'subject' => $subject,
                    'body' => $body,
                    'status' => 'queued',
                    'attempt' => 0,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'queued_at' => Carbon::now(),
                ]);

                // Sync kuyrukta (test/geliştirme) sağlayıcı hatası isteğe sızmasın: kayıt failed olur, iş kaydı bozulmaz (§19).
                try {
                    SendNotification::dispatch($log->id);
                } catch (\Throwable $e) {
                    report($e);
                }
                $logs->push($log);
            }
        }

        return $logs;
    }

    /**
     * Şablonu yükle ve yer tutucuları doldur. Bilinmeyen yer tutucu boş basılır.
     *
     * @param  array<string, string|int|float|null>  $payload
     * @return array{0: string|null, 1: string, 2: string}
     */
    public function render(string $event, string $channel, array $payload): array
    {
        $locale = $this->settings->string('whatsapp.template_locale');
        $tpl = NotificationTemplate::query()->where('event', $event)->where('channel', $channel)->where('locale', $locale)->first()
            ?? NotificationTemplate::query()->where('event', $event)->where('channel', $channel)->where('locale', 'tr')->first();
        $def = NotificationEvents::definition($event);

        $subject = $tpl->subject ?? $def['subject'];
        $body = $tpl->body ?? $def['body'];
        $name = $tpl ? "db:{$event}:{$channel}:{$tpl->locale}" : "default:{$event}";

        $fill = fn (string $text): string => (string) preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', fn ($m) => (string) ($payload[$m[1]] ?? ''), $text);

        return [$channel === 'in_app' || $channel === 'email' ? mb_substr($fill($subject), 0, 160) : null, $fill($body), $name];
    }

    /**
     * @param  array<string, string|int|float|null>  $payload
     * @return array<int, array{id: int|null, address: string}>
     */
    private function recipientsFor(string $group, string $channel, ?int $locationId, array $payload): array
    {
        if ($group === NotificationEvents::CUSTOMER_GROUP) {
            return match ($channel) {
                'email' => $this->settings->bool('notifications.customer_email_enabled') && ! empty($payload['customer_email']) ? [['id' => null, 'address' => (string) $payload['customer_email']]] : [],
                'whatsapp', 'sms' => ! empty($payload['customer_phone']) ? [['id' => null, 'address' => (string) $payload['customer_phone']]] : [],
                'in_app' => ! empty($payload['customer_user_id']) ? [['id' => null, 'address' => 'user:'.$payload['customer_user_id']]] : [],
                default => [],
            };
        }

        return NotificationRecipient::query()
            ->where('group', $group)->where('channel', $channel)->where('is_active', true)
            // Lokasyon bağlı alıcı yalnız kendi lokasyonunun olaylarını alır; lokasyonsuz alıcı hepsini.
            ->where(fn (Builder $q) => $q->whereNull('location_id')->when($locationId !== null, fn (Builder $w) => $w->orWhere('location_id', $locationId)))
            ->get()
            ->map(fn (NotificationRecipient $r) => ['id' => $r->id, 'address' => $r->channel === 'in_app' ? 'user:'.$r->user_id : (string) $r->address])
            ->filter(fn (array $r) => $r['address'] !== '' && $r['address'] !== 'user:')
            ->values()
            ->all();
    }

    /** Etkin kurallar (önbellekli düz dizi). @return array<int, array{channel: string, group: string}> */
    private function rules(string $event): array
    {
        $all = Cache::remember(self::CACHE_KEY, 3600, fn () => NotificationRule::query()->where('enabled', true)->get()
            ->groupBy('event')
            ->map(fn ($g) => $g->map(fn (NotificationRule $r) => ['channel' => $r->channel, 'group' => $r->recipient_group])->values()->all())
            ->all());
        $rows = $all[$event] ?? [];

        return array_values(array_filter($rows, fn ($r) => is_array($r) && isset($r['channel'], $r['group'])));
    }

    // ---- Panel: kurallar ----------------------------------------------------

    /** Olay × kanal × grup matrisi (DB satırı yoksa: pasif). @return array<string, array<string, array<string, bool>>> */
    public function rulesMatrix(): array
    {
        $rows = NotificationRule::query()->get();
        $matrix = [];

        foreach (NotificationEvents::registry() as $event => $def) {
            foreach (NotificationEvents::CHANNELS as $channel => $l) {
                foreach (NotificationEvents::GROUPS as $group => $gl) {
                    $row = $rows->first(fn (NotificationRule $r) => $r->event === $event && $r->channel === $channel && $r->recipient_group === $group);
                    $matrix[$event][$channel][$group] = $row->enabled ?? false;
                }
            }
        }

        return $matrix;
    }

    /** @param  array<string, array<string, array<string, bool>>>  $matrix */
    public function saveRules(User $actor, array $matrix): void
    {
        $before = $this->rulesMatrix();

        foreach (NotificationEvents::registry() as $event => $def) {
            foreach (NotificationEvents::CHANNELS as $channel => $l) {
                foreach (NotificationEvents::GROUPS as $group => $gl) {
                    $enabled = (bool) ($matrix[$event][$channel][$group] ?? false);
                    NotificationRule::query()->updateOrCreate(['event' => $event, 'channel' => $channel, 'recipient_group' => $group], ['enabled' => $enabled, 'updated_by' => $actor->id]);
                }
            }
        }

        Cache::forget(self::CACHE_KEY);
        $this->audit->record($actor, 'notification.rules_changed', 'notification_rules', null, ['enabled' => $this->flatten($before)], ['enabled' => $this->flatten($this->rulesMatrix())]);
    }

    /** Varsayılan kural setini (NotificationEvents::defaults) yalnız hiç kural yoksa yazar (referans veri). */
    public function seedDefaultRules(): int
    {
        if (NotificationRule::query()->exists()) {
            return 0;
        }

        $n = 0;

        foreach (NotificationEvents::registry() as $event => $def) {
            foreach ($def['defaults'] as [$channel, $group]) {
                NotificationRule::query()->create(['event' => $event, 'channel' => $channel, 'recipient_group' => $group, 'enabled' => true]);
                $n++;
            }
        }

        Cache::forget(self::CACHE_KEY);

        return $n;
    }

    // ---- Panel: alıcılar ----------------------------------------------------

    /** @return Collection<int, NotificationRecipient> */
    public function recipients(): Collection
    {
        return NotificationRecipient::query()->with(['user', 'location'])->orderBy('group')->orderBy('channel')->orderBy('name')->get();
    }

    /**
     * @param  array{name: string, channel: string, address?: string|null, user_id?: int|null, group: string, location_id?: int|null, is_active?: bool}  $data
     */
    public function saveRecipient(User $actor, array $data, ?NotificationRecipient $recipient = null): NotificationRecipient
    {
        if (! isset(NotificationEvents::CHANNELS[$data['channel']]) || ! isset(NotificationEvents::GROUPS[$data['group']]) || $data['group'] === NotificationEvents::CUSTOMER_GROUP) {
            throw new DomainException('Geçersiz kanal ya da grup.');
        }

        $address = trim((string) ($data['address'] ?? ''));

        if ($data['channel'] === 'in_app') {
            if (empty($data['user_id']) || ! User::query()->whereKey($data['user_id'])->exists()) {
                throw new DomainException('Uygulama içi alıcı için kullanıcı seçin.');
            }
            $address = '';
        } elseif ($data['channel'] === 'email') {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw new DomainException('Geçerli bir e-posta girin.');
            }
        } elseif (preg_match('/^\+[1-9][0-9]{7,14}$/', $address) !== 1) {
            throw new DomainException('Telefon E.164 biçiminde olmalı (+90…).');
        }

        if (! empty($data['location_id']) && ! Location::query()->whereKey($data['location_id'])->exists()) {
            throw new DomainException('Lokasyon bulunamadı.');
        }

        $before = $recipient?->toArray() ?? [];
        $recipient ??= new NotificationRecipient;
        $recipient->fill([
            'name' => trim($data['name']),
            'channel' => $data['channel'],
            'address' => $address === '' ? null : $address,
            'user_id' => $data['channel'] === 'in_app' ? (int) $data['user_id'] : null,
            'group' => $data['group'],
            'location_id' => ! empty($data['location_id']) ? (int) $data['location_id'] : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'updated_by' => $actor->id,
        ])->save();

        $this->audit->record($actor, $before === [] ? 'notification.recipient_created' : 'notification.recipient_updated', 'notification_recipient', $recipient->id, $before, $recipient->toArray());

        return $recipient;
    }

    public function setRecipientActive(User $actor, NotificationRecipient $recipient, bool $active): void
    {
        $before = ['is_active' => $recipient->is_active];
        $recipient->forceFill(['is_active' => $active, 'updated_by' => $actor->id])->save();
        $this->audit->record($actor, 'notification.recipient_updated', 'notification_recipient', $recipient->id, $before, ['is_active' => $active]);
    }

    public function deleteRecipient(User $actor, NotificationRecipient $recipient): void
    {
        $before = $recipient->toArray();
        $recipient->delete();
        $this->audit->record($actor, 'notification.recipient_deleted', 'notification_recipient', $recipient->id, $before, []);
    }

    // ---- Panel: şablonlar ---------------------------------------------------

    /** @return array{subject: string|null, body: string, stored: bool} */
    public function template(string $event, string $channel, string $locale = 'tr'): array
    {
        $tpl = NotificationTemplate::query()->where('event', $event)->where('channel', $channel)->where('locale', $locale)->first();
        $def = NotificationEvents::definition($event);

        return ['subject' => $tpl->subject ?? $def['subject'], 'body' => $tpl->body ?? $def['body'], 'stored' => $tpl !== null];
    }

    public function saveTemplate(User $actor, string $event, string $channel, string $locale, ?string $subject, string $body): void
    {
        NotificationEvents::definition($event);

        if (! isset(NotificationEvents::CHANNELS[$channel])) {
            throw new DomainException('Geçersiz kanal.');
        }

        $before = $this->template($event, $channel, $locale);

        if (trim($body) === '') {
            NotificationTemplate::query()->where('event', $event)->where('channel', $channel)->where('locale', $locale)->delete();
        } else {
            NotificationTemplate::query()->updateOrCreate(['event' => $event, 'channel' => $channel, 'locale' => $locale], ['subject' => $subject !== null && trim($subject) !== '' ? trim($subject) : null, 'body' => $body, 'updated_by' => $actor->id]);
        }

        $this->audit->record($actor, 'notification.template_changed', 'notification_template', null, ['event' => $event, 'channel' => $channel, 'body' => $before['body']], ['event' => $event, 'channel' => $channel, 'body' => $this->template($event, $channel, $locale)['body']]);
    }

    // ---- Panel: günlük ------------------------------------------------------

    /**
     * @param  array{status?: string|null, channel?: string|null, event?: string|null}  $filters
     * @return LengthAwarePaginator<int, NotificationLog>
     */
    public function logs(array $filters, int $perPage = 50): LengthAwarePaginator
    {
        return NotificationLog::query()
            ->when($filters['status'] ?? null, fn (Builder $q, $s) => $q->where('status', $s))
            ->when($filters['channel'] ?? null, fn (Builder $q, $c) => $q->where('channel', $c))
            ->when($filters['event'] ?? null, fn (Builder $q, $e) => $q->where('event', $e))
            ->orderByDesc('created_at')
            ->paginate($perPage)->withQueryString();
    }

    /** @return Collection<int, NotificationLog> */
    public function logsFor(string $entityType, int $entityId): Collection
    {
        return NotificationLog::query()->where('entity_type', $entityType)->where('entity_id', $entityId)->orderBy('created_at')->get();
    }

    /** @return array{queued: int, failed: int, sent_today: int} */
    public function counts(): array
    {
        return [
            'queued' => NotificationLog::query()->where('status', 'queued')->count(),
            'failed' => NotificationLog::query()->where('status', 'failed')->count(),
            'sent_today' => NotificationLog::query()->whereIn('status', ['sent', 'delivered'])->where('sent_at', '>=', Carbon::today())->count(),
        ];
    }

    /** @param  array<string, array<string, array<string, bool>>>  $matrix  @return array<int, string> */
    private function flatten(array $matrix): array
    {
        $out = [];

        foreach ($matrix as $event => $channels) {
            foreach ($channels as $channel => $groups) {
                foreach ($groups as $group => $enabled) {
                    if ($enabled) {
                        $out[] = "{$event}:{$channel}:{$group}";
                    }
                }
            }
        }

        return $out;
    }
}
