<?php

namespace App\Services;

use App\Models\Lead;
use App\Webhooks\WebhookDispatcher;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Siteden gelen teklif ve ön rezervasyon taleplerinin iş mantığı.
 *
 * NEDEN AYRI SERVİS: LeadController doğrudan `Lead::create()` çağırıyordu ve
 * bu, CLAUDE.md'deki "controller'dan doğrudan DB erişimi yasağı" kuralının
 * ihlaliydi. İhlali mimari testi yakaladı (tests/Architecture) — kural
 * yazılmadan önce kod zaten yanlıştı, kural onu görünür kıldı.
 *
 * HTTP'ye bağlı DEĞİLDİR: rıza kanıtı (IP, user-agent) parametre olarak gelir,
 * servis içinden request() okunmaz. Böylece aynı servis queue job'ından veya
 * konsoldan da çağrılabilir.
 */
class LeadService
{
    public function __construct(private readonly NotificationService $notifications, private readonly WebhookDispatcher $webhooks) {}

    /**
     * @param  array{kind: string, name: string, email: string, phone?: ?string,
     *               location_id?: ?int, solution?: ?string, team_size?: ?string,
     *               requested_date?: ?string, requested_slot?: ?string, note?: ?string}  $data
     * @param  array{ip?: ?string, user_agent?: ?string}  $consent  KVKK açık rıza kanıtı
     */
    public function capture(array $data, array $consent): Lead
    {
        $lead = Lead::create([
            'kind' => $data['kind'],
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'solution' => $data['solution'] ?? null,
            'team_size' => $data['team_size'] ?? null,
            'requested_date' => $data['requested_date'] ?? null,
            'requested_slot' => $data['requested_slot'] ?? null,
            'note' => $data['note'] ?? null,

            // KVKK: rıza anı, IP ve tarayıcı bilgisi kanıt olarak saklanır.
            // Boolean bir kutu, açık rızanın kanıtı değildir.
            'consented_at' => now(),
            'consent_ip' => $consent['ip'] ?? null,
            'consent_user_agent' => isset($consent['user_agent'])
                ? mb_substr((string) $consent['user_agent'], 0, 255)
                : null,
        ]);

        // Bildirim merkezi: CRM grubuna yeni talep (kural/alıcı panelden).
        $this->notifications->dispatch('lead.created', [
            'name' => $lead->name, 'email' => $lead->email, 'phone' => (string) ($lead->phone ?? ''), 'kind' => $lead->kind,
            'solution' => (string) ($lead->solution ?? ''), 'location' => (string) ($lead->location->name ?? ''),
        ], $lead->location_id, 'lead', $lead->id);
        $this->webhooks->emit('lead.created', ['lead_id' => $lead->id, 'kind' => $lead->kind, 'name' => $lead->name, 'email' => $lead->email, 'phone' => $lead->phone, 'location_id' => $lead->location_id, 'solution' => $lead->solution, 'team_size' => $lead->team_size, 'requested_date' => $lead->requested_date?->toDateString(), 'created_at' => $lead->created_at?->toIso8601String()]);

        return $lead;
    }

    /**
     * Panel listesi (lead.view): tür/durum süzgeci, ad/e-posta/telefon araması, sayfalama.
     *
     * @param  array{kind?: string|null, status?: string|null, q?: string|null}  $filters
     * @return LengthAwarePaginator<int, Lead>
     */
    public function paginate(array $filters, int $perPage = 30): LengthAwarePaginator
    {
        $q = trim((string) ($filters['q'] ?? ''));

        return Lead::query()
            ->with(['location', 'assignee'])
            ->when($filters['kind'] ?? null, fn ($b, $kind) => $b->where('kind', $kind))
            ->when($filters['status'] ?? null, fn ($b, $status) => $b->where('status', $status))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")))
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** lead.assign: personel ataması + durum + iç not. Durum STATUSES dışında olamaz. */
    public function update(Lead $lead, ?int $assignedTo, string $status, ?string $internalNote): Lead
    {
        if (! array_key_exists($status, Lead::STATUSES)) {
            throw new DomainException('Geçersiz talep durumu: '.$status);
        }

        $lead->assigned_to = $assignedTo;
        $lead->status = $status;
        $lead->internal_note = $internalNote;

        if ($status !== 'new' && $lead->handled_at === null) {
            $lead->handled_at = now();
        }

        $lead->save();

        return $lead;
    }

    public function summary(Lead $lead): string
    {
        if ($lead->kind === 'booking') {
            return sprintf(
                '%s · %s %s için ön talebiniz alındı. Uygunluk teyidi e-posta ile gelecek.',
                $lead->location->name ?? 'Lokasyon',
                $lead->requested_date?->format('d.m.Y') ?? '',
                $lead->requested_slot ?? ''
            );
        }

        return sprintf(
            '%s için talebiniz alındı. %s adresine aynı iş günü içinde dönüş yapacağız.',
            $lead->solution ?? 'Çözüm',
            $lead->email
        );
    }
}
