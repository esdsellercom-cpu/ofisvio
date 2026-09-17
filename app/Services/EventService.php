<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Models\Website;
use App\Support\Money;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Etkinlikler & topluluk (faz 39d, artifact §9): etkinlikler panelden açılır, yayınlananlar
 * vitrinde listelenir (önbellekli; değişince site sürümü düşer), vitrin kayıt formu
 * kapasite/açık-kapalı kurallarına tabidir. Admin işlemleri audit izli.
 */
class EventService
{
    public const TABS = ['upcoming' => 'Yaklaşan', 'past' => 'Geçmiş', 'draft' => 'Taslak', 'all' => 'Tümü'];

    public function __construct(private readonly AuditService $audit, private readonly ContentCache $cache) {}

    // ---- Vitrin -----------------------------------------------------------------

    /**
     * Yayındaki yaklaşan etkinlikler (bitişi geçmemiş), tarih sırasıyla; site sürüm önbelleğinde.
     *
     * @return Collection<int, Event>
     */
    public function upcoming(?Website $website): Collection
    {
        $compute = fn () => Event::query()->published()->where('ends_at', '>=', Carbon::now())->with(['location', 'cover'])->withCount(['registrations as registrations_active_count' => fn (Builder $q) => $q->where('status', '!=', 'cancelled')])->orderBy('starts_at')->get();

        if ($website === null) {
            return $compute();
        }

        $rows = $this->cache->remember($website, 'events', fn () => $compute()->map(fn (Event $e): array => $e->getAttributes() + ['registrations_active_count' => $e->activeRegistrations()])->all());

        return Event::query()->hydrate(is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [])->load(['location', 'cover']);
    }

    public function findPublished(string $slug): ?Event
    {
        return Event::query()->published()->where('slug', $slug)->with(['location', 'room', 'cover'])->withCount(['registrations as registrations_active_count' => fn (Builder $q) => $q->where('status', '!=', 'cancelled')])->first();
    }

    /**
     * Vitrin kaydı: yayın + açık + başlamamış + kapasite + aynı e-posta bir kez.
     *
     * @param  array{name: string, email: string, phone?: string|null, company_name?: string|null, note?: string|null}  $data
     * @param  array{ip?: string|null}  $consent
     */
    public function register(Event $event, array $data, array $consent): EventRegistration
    {
        if (! $event->is_published || ! $event->registration_open) {
            throw new DomainException('Bu etkinliğe kayıt kapalı.');
        }

        if ($event->starts_at->isPast()) {
            throw new DomainException('Etkinlik başladı; kayıt alınmıyor.');
        }

        if ($event->isFull()) {
            throw new DomainException('Kontenjan doldu.');
        }

        $email = mb_strtolower(trim($data['email']));

        if ($event->registrations()->where('email', $email)->where('status', '!=', 'cancelled')->exists()) {
            throw new DomainException('Bu e-posta ile kayıt zaten var.');
        }

        $registration = new EventRegistration([
            'event_id' => $event->id,
            'name' => trim($data['name']),
            'email' => $email,
            'phone' => $this->blank($data['phone'] ?? null),
            'company_name' => $this->blank($data['company_name'] ?? null),
            'note' => $this->blank($data['note'] ?? null),
            'status' => 'registered',
            'consented_at' => Carbon::now(),
            'consent_ip' => $consent['ip'] ?? null,
        ]);
        $registration->save();
        $this->bump();

        return $registration;
    }

    // ---- Panel ------------------------------------------------------------------

    /** @return Collection<int, Event> */
    public function all(string $tab = 'upcoming'): Collection
    {
        $now = Carbon::now();

        return Event::query()->with('location')->withCount(['registrations as registrations_active_count' => fn (Builder $q) => $q->where('status', '!=', 'cancelled')])
            ->when($tab === 'upcoming', fn (Builder $q) => $q->where('is_published', true)->where('ends_at', '>=', $now))
            ->when($tab === 'past', fn (Builder $q) => $q->where('ends_at', '<', $now))
            ->when($tab === 'draft', fn (Builder $q) => $q->where('is_published', false))
            ->orderBy($tab === 'past' ? 'starts_at' : 'starts_at', $tab === 'past' ? 'desc' : 'asc')
            ->get();
    }

    public function find(string $slug): ?Event
    {
        return Event::query()->where('slug', $slug)->with(['location', 'room', 'registrations' => fn ($q) => $q->orderBy('created_at')])->first();
    }

    /** @param  array<string, mixed>  $data */
    public function create(User $actor, array $data): Event
    {
        $event = new Event($this->attributes($data));
        $event->slug = $this->uniqueSlug((string) $data['title']);
        $event->created_by = $actor->id;
        $event->updated_by = $actor->id;
        $event->save();
        $this->audit->record($actor, 'event.created', 'event', $event->id, [], $event->toArray());
        $this->bump();

        return $event;
    }

    /** @param  array<string, mixed>  $data */
    public function update(User $actor, Event $event, array $data): Event
    {
        $before = $event->toArray();
        $event->fill($this->attributes($data));
        $event->updated_by = $actor->id;
        $event->save(); // slug sabit (vitrin bağlantıları)
        $this->audit->record($actor, 'event.updated', 'event', $event->id, $before, $event->toArray());
        $this->bump();

        return $event;
    }

    /** Kaydı olan etkinlik silinmez (katılımcı listesi korunur); yayından kaldırılır. */
    public function delete(User $actor, Event $event): void
    {
        if ($event->registrations()->exists()) {
            throw new DomainException('Kaydı olan etkinlik silinemez; yayından kaldırın.');
        }

        $before = $event->toArray();
        $event->delete();
        $this->audit->record($actor, 'event.deleted', 'event', $event->id, $before, []);
        $this->bump();
    }

    public function setRegistrationStatus(User $actor, EventRegistration $registration, string $status): EventRegistration
    {
        if (! isset(EventRegistration::STATUSES[$status])) {
            throw new DomainException('Geçersiz kayıt durumu.');
        }

        $before = $registration->toArray();
        $registration->fill(['status' => $status])->save();
        $this->audit->record($actor, 'event.registration_updated', 'event_registration', $registration->id, $before, $registration->toArray());
        $this->bump();

        return $registration;
    }

    /** @return array{upcoming: int, registrations_30d: int, next: Event|null} */
    public function dashboard(): array
    {
        $now = Carbon::now();

        return [
            'upcoming' => Event::query()->published()->where('ends_at', '>=', $now)->count(),
            'registrations_30d' => EventRegistration::query()->where('created_at', '>=', $now->copy()->subDays(30))->where('status', '!=', 'cancelled')->count(),
            'next' => Event::query()->published()->where('ends_at', '>=', $now)->with('location')->orderBy('starts_at')->first(),
        ];
    }

    /** @param  array<string, mixed>  $data  @return array<string, mixed> */
    private function attributes(array $data): array
    {
        $starts = Carbon::parse((string) $data['starts_at']);
        $ends = Carbon::parse((string) $data['ends_at']);

        if ($ends->lte($starts)) {
            throw new DomainException('Bitiş başlangıçtan sonra olmalı.');
        }

        return [
            'title' => trim((string) $data['title']),
            'summary' => $this->blank($data['summary'] ?? null),
            'description' => $this->blank($data['description'] ?? null),
            'location_id' => ! empty($data['location_id']) ? (int) $data['location_id'] : null,
            'room_id' => ! empty($data['room_id']) ? (int) $data['room_id'] : null,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'capacity' => isset($data['capacity']) && $data['capacity'] !== '' ? max(1, (int) $data['capacity']) : null,
            'price' => max(0, Money::parse((string) ($data['price'] ?? 0))),
            'is_published' => (bool) ($data['is_published'] ?? false),
            'registration_open' => (bool) ($data['registration_open'] ?? true),
            'cover_media_id' => ! empty($data['cover_media_id']) ? (int) $data['cover_media_id'] : null,
        ];
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'etkinlik';
        $slug = $base;

        for ($i = 2; Event::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    private function blank(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function bump(): void
    {
        foreach (Website::query()->get() as $website) {
            $this->cache->invalidate($website);
        }
    }
}
