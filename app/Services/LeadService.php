<?php

namespace App\Services;

use App\Models\Lead;

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
    /**
     * @param  array{kind: string, name: string, email: string, phone?: ?string,
     *               location_id?: ?int, solution?: ?string, team_size?: ?string,
     *               requested_date?: ?string, requested_slot?: ?string, note?: ?string}  $data
     * @param  array{ip?: ?string, user_agent?: ?string}  $consent  KVKK açık rıza kanıtı
     */
    public function capture(array $data, array $consent): Lead
    {
        return Lead::create([
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
