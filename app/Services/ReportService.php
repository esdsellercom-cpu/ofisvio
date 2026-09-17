<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Company;
use App\Models\Content;
use App\Models\Lead;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Raporlar & analitik (faz 39, artifact §16): var olan verinin toplamları — sahte
 * seri yok, dış analitik yok. Sekmeler: gelir (rezervasyon), doluluk (alanlar),
 * üyelik (şirketler/KYC), talepler (CRM), bildirim (kanal sağlığı), içerik.
 *
 * withoutTenantScope: yalnız analytics.view (global) rotasından; şirket sayımı
 * TÜM organizasyonlar için duruma göre ADET döner — satır dönmez, isim dönmez
 * (ArchitectureTest allowlist gerekçesi).
 */
class ReportService
{
    public const TABS = ['gelir' => 'Gelir', 'tahsilat' => 'Tahsilat', 'doluluk' => 'Doluluk', 'uyelik' => 'Üyelik', 'talepler' => 'Talepler', 'bildirim' => 'Bildirim', 'icerik' => 'İçerik'];

    public function __construct(
        private readonly BookingService $bookings,
        private readonly KycQueueService $kyc,
        private readonly NotificationService $notifications,
        private readonly AuthorizationService $authorization,
        private readonly InvoiceService $invoices,
        private readonly SubscriptionService $subscriptions,
        private readonly FranchiseService $franchise,
    ) {}

    /** @return array<string, mixed> */
    public function tab(User $user, string $tab): array
    {
        return match ($tab) {
            'gelir', 'doluluk' => ['bookings' => $this->bookings->dashboard(), 'tabs' => $this->bookings->tabCounts(), 'rooms' => $this->rooms(), 'finance' => $this->finance($user)],
            // Finans toplamları yalnız invoice.view taşıyana (analytics.view operasyonda da var).
            'tahsilat' => $this->finance($user) ?? [],
            // KYC adedi yalnız kyc.view_status taşıyana (operations_admin analytics.view taşır ama KYC görmez).
            'uyelik' => ['companies' => $this->companiesByStatus(), 'kyc_pending' => $this->authorization->can($user, 'kyc.view_status') ? array_sum($this->kyc->pendingCounts($user)) : null, 'subscriptions' => $this->authorization->can($user, 'subscription.view') ? $this->subscriptions->dashboard() : null],
            'talepler' => $this->leads() + ['franchise' => $this->authorization->can($user, 'franchise.view') ? $this->franchise->counts() : null],
            'bildirim' => ['health' => $this->notifications->channelHealth(30), 'counts' => $this->notifications->counts()],
            'icerik' => ['content' => $this->contentByStatus()],
            default => [],
        };
    }

    /**
     * Fatura/tahsilat toplamları (invoice.view yoksa null).
     *
     * @return array{invoices: array{revenue_today: int, revenue_month: int, outstanding: int, outstanding_count: int, overdue: int, overdue_count: int, due_7d_count: int}, monthly: array<int, array{month: string, amount: int}>}|null
     */
    private function finance(User $user): ?array
    {
        if (! $this->authorization->can($user, 'invoice.view')) {
            return null;
        }

        return ['invoices' => $this->invoices->dashboard(), 'monthly' => $this->invoices->monthlyRevenue(6)];
    }

    /** @return array{total: int, active: int, by_kind: array<string, array{label: string, count: int, capacity: int}>} */
    private function rooms(): array
    {
        $rooms = Room::query()->get(['kind', 'capacity', 'is_active']);
        $byKind = [];

        foreach (Room::KINDS as $kind => $label) {
            $group = $rooms->where('kind', $kind);
            $byKind[$kind] = ['label' => $label, 'count' => $group->count(), 'capacity' => (int) $group->sum('capacity')];
        }

        return ['total' => $rooms->count(), 'active' => $rooms->where('is_active', true)->count(), 'by_kind' => $byKind];
    }

    /** @return array<string, array{label: string, count: int}> */
    private function companiesByStatus(): array
    {
        $rows = Company::withoutTenantScope()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $out = [];

        foreach (CompanyStatus::cases() as $status) {
            $out[$status->value] = ['label' => $status->label(), 'count' => (int) ($rows[$status->value] ?? 0)];
        }

        return $out;
    }

    /** @return array{by_status: array<string, array{label: string, count: int}>, by_kind: array<string, int>, last_30d: int, conversion: float} */
    private function leads(): array
    {
        $rows = Lead::query()->selectRaw('status, kind, count(*) as n')->groupBy('status', 'kind')->get();
        $byStatus = [];
        $byKind = ['quote' => 0, 'booking' => 0];

        foreach (Lead::STATUSES as $key => $label) {
            $byStatus[$key] = ['label' => $label, 'count' => (int) $rows->where('status', $key)->sum('n')];
        }

        foreach ($rows as $row) {
            $byKind[(string) $row->getAttribute('kind')] = ($byKind[(string) $row->getAttribute('kind')] ?? 0) + (int) $row->getAttribute('n');
        }

        $decided = $byStatus['won']['count'] + $byStatus['lost']['count'];

        return [
            'by_status' => $byStatus,
            'by_kind' => $byKind,
            'last_30d' => Lead::query()->where('created_at', '>=', Carbon::now()->subDays(30))->count(),
            'conversion' => $decided > 0 ? round($byStatus['won']['count'] / $decided * 100, 1) : 0.0,
        ];
    }

    /** @return array<string, array{label: string, page: int, post: int}> */
    private function contentByStatus(): array
    {
        $rows = Content::query()->selectRaw('status, kind, count(*) as n')->groupBy('status', 'kind')->get();
        $out = [];

        foreach (ContentStatus::cases() as $status) {
            $out[$status->value] = [
                'label' => $status->label(),
                'page' => (int) $rows->where('status', $status)->where('kind', ContentKind::PAGE)->sum('n'),
                'post' => (int) $rows->where('status', $status)->where('kind', ContentKind::POST)->sum('n'),
            ];
        }

        return $out;
    }
}
