<?php

namespace App\Webhooks;

use App\Integrations\UrlGuard;
use App\Jobs\DeliverWebhook;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\AuditService;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Webhook merkezi (faz 61c): uç nokta CRUD (URL https + genel ana bilgisayar, secret şifreli, olay seçimi,
 * aktif/pasif, retry, timeout), test gönderimi (ping), başarısız teslimatı tekrar gönderme, log/istatistik.
 * Secret panelde gösterilmez; yalnız yeni üretildiği anda BİR kez döner (alıcı tarafa girilmesi için).
 * Her yazma audit'e düşer (secret değeri değil, "secret_rotated" bayrağı).
 */
class WebhookService
{
    public const RETRY_MAX = 10;

    public const TIMEOUT_MAX = 30;

    public function __construct(private readonly AuditService $audit, private readonly UrlGuard $guard, private readonly WebhookDispatcher $dispatcher) {}

    /** @return Collection<int, WebhookEndpoint> */
    public function endpoints(): Collection
    {
        return WebhookEndpoint::query()->withCount([
            'deliveries as failed_count' => fn ($q) => $q->where('status', 'failed'),
            'deliveries as deliveries_count',
        ])->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{endpoint: WebhookEndpoint, secret: string|null} secret yalnız üretildiyse dolu (bir kez gösterilir)
     */
    public function create(User $actor, array $data): array
    {
        $clean = $this->validate($data);
        $secret = trim((string) ($data['secret'] ?? ''));
        $generated = $secret === '';
        $secret = $generated ? 'whsec_'.Str::lower(Str::random(40)) : $secret;

        $endpoint = WebhookEndpoint::create($clean + ['secret' => $secret, 'created_by' => $actor->id]);
        $this->audit->record($actor, 'webhook.created', 'webhook_endpoint', $endpoint->id, [], $clean + ['secret_generated' => $generated]);

        return ['endpoint' => $endpoint, 'secret' => $generated ? $secret : null];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{endpoint: WebhookEndpoint, secret: string|null}
     */
    public function update(User $actor, WebhookEndpoint $endpoint, array $data): array
    {
        $clean = $this->validate($data);
        $before = $endpoint->only(array_keys($clean));
        $rotate = ! empty($data['rotate_secret']);
        $newSecret = trim((string) ($data['secret'] ?? ''));
        $shown = null;

        if ($rotate) {
            $newSecret = 'whsec_'.Str::lower(Str::random(40));
            $shown = $newSecret;
        }

        if ($newSecret !== '') {
            $clean['secret'] = $newSecret;
        }

        $endpoint->fill($clean)->save();
        $this->audit->record($actor, 'webhook.updated', 'webhook_endpoint', $endpoint->id, $before, array_diff_key($clean, ['secret' => 1]) + ['secret_rotated' => $newSecret !== '']);

        return ['endpoint' => $endpoint, 'secret' => $shown];
    }

    public function toggle(User $actor, WebhookEndpoint $endpoint): WebhookEndpoint
    {
        $endpoint->forceFill(['is_active' => ! $endpoint->is_active])->save();
        $this->audit->record($actor, $endpoint->is_active ? 'webhook.activated' : 'webhook.deactivated', 'webhook_endpoint', $endpoint->id, ['is_active' => ! $endpoint->is_active], ['is_active' => $endpoint->is_active]);

        return $endpoint;
    }

    public function delete(User $actor, WebhookEndpoint $endpoint): void
    {
        $this->audit->record($actor, 'webhook.deleted', 'webhook_endpoint', $endpoint->id, ['name' => $endpoint->name, 'url' => $endpoint->url], []);
        $endpoint->delete();
    }

    /** Test gönderimi: `ping` olayı, uç pasif olsa da gider (yapılandırmayı doğrulamak için). */
    public function sendTest(User $actor, WebhookEndpoint $endpoint): WebhookDelivery
    {
        $delivery = $this->dispatcher->queue($endpoint, WebhookEvents::PING, ['message' => 'Ofisvio webhook testi', 'endpoint' => $endpoint->name], true);
        $this->audit->record($actor, 'webhook.tested', 'webhook_endpoint', $endpoint->id, [], ['delivery_id' => $delivery->delivery_id]);

        return $delivery;
    }

    /** Başarısız teslimatı elle tekrar kuyruğa alır: tek deneme (sayaç sıfırlanmaz; otomatik yeniden deneme açılmaz). */
    public function resend(User $actor, WebhookDelivery $delivery): WebhookDelivery
    {
        if ($delivery->status === 'success') {
            throw new DomainException('Bu teslimat zaten başarılı; tekrar gönderilmez.');
        }

        $delivery->forceFill(['status' => 'pending', 'next_retry_at' => null, 'manual' => true, 'error' => null])->save();
        DeliverWebhook::dispatch($delivery->id);
        $this->audit->record($actor, 'webhook.resent', 'webhook_delivery', $delivery->id, [], ['delivery_id' => $delivery->delivery_id, 'event' => $delivery->event]);

        return $delivery;
    }

    /**
     * Düzenleme ekranı: ucun son teslimatları.
     *
     * @return Collection<int, WebhookDelivery>
     */
    public function recent(WebhookEndpoint $endpoint): Collection
    {
        return $endpoint->deliveries()->latest('id')->limit(8)->get();
    }

    /** @return LengthAwarePaginator<int, WebhookDelivery> */
    public function deliveries(?int $endpointId, string $status, string $event): LengthAwarePaginator
    {
        return WebhookDelivery::query()->with('endpoint')
            ->when($endpointId !== null, fn ($q) => $q->where('endpoint_id', $endpointId))
            ->when(isset(WebhookDelivery::STATUSES[$status]), fn ($q) => $q->where('status', $status))
            ->when($event !== '', fn ($q) => $q->where('event', $event))
            ->latest('id')->paginate(50)->withQueryString();
    }

    /** @return array{total_24h: int, failed_24h: int, pending: int, avg_ms_24h: int} */
    public function stats(): array
    {
        $day = WebhookDelivery::query()->where('created_at', '>=', now()->subDay());

        return [
            'total_24h' => (clone $day)->count(),
            'failed_24h' => (clone $day)->where('status', 'failed')->count(),
            'pending' => WebhookDelivery::query()->where('status', 'pending')->count(),
            'avg_ms_24h' => (int) round((float) (clone $day)->whereNotNull('duration_ms')->avg('duration_ms')),
        ];
    }

    /** Sağlık kartı: aktif uç sayısı, son başarılı/son hata (faz 61b sağlık ekranı). @return array{active: int, last_ok_at: string|null, last_error_at: string|null, failed_24h: int} */
    public function health(): array
    {
        return [
            'active' => WebhookEndpoint::query()->where('is_active', true)->count(),
            'last_ok_at' => WebhookEndpoint::query()->max('last_success_at'),
            'last_error_at' => WebhookEndpoint::query()->max('last_failure_at'),
            'failed_24h' => WebhookDelivery::query()->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, url: string, events: list<string>, is_active: bool, retry_max: int, timeout_seconds: int, description: string|null}
     */
    private function validate(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $url = trim((string) ($data['url'] ?? ''));
        $events = array_values(array_unique(array_filter(array_map('strval', (array) ($data['events'] ?? [])), fn (string $e) => WebhookEvents::valid($e))));

        if ($name === '' || mb_strlen($name) > 120) {
            throw new DomainException('Webhook adı gerekli (en çok 120 karakter).');
        }

        $parts = parse_url($url);

        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || mb_strlen($url) > 500 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new DomainException('Webhook adresi https:// ile başlayan geçerli bir adres olmalı.');
        }

        try {
            $this->guard->assertPublicHost((string) $parts['host']);
        } catch (RuntimeException $e) {
            throw new DomainException('Webhook adresi reddedildi: '.$e->getMessage());
        }

        if ($events === []) {
            throw new DomainException('En az bir olay seçin.');
        }

        $retry = (int) ($data['retry_max'] ?? 3);
        $timeout = (int) ($data['timeout_seconds'] ?? 10);

        if ($retry < 0 || $retry > self::RETRY_MAX) {
            throw new DomainException('Yeniden deneme 0–'.self::RETRY_MAX.' arasında olmalı.');
        }

        if ($timeout < 1 || $timeout > self::TIMEOUT_MAX) {
            throw new DomainException('Zaman aşımı 1–'.self::TIMEOUT_MAX.' saniye arasında olmalı.');
        }

        return [
            'name' => $name,
            'url' => $url,
            'events' => $events,
            'is_active' => ! empty($data['is_active']),
            'retry_max' => $retry,
            'timeout_seconds' => $timeout,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
        ];
    }
}
