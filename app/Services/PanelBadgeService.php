<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\NotificationLog;
use App\Models\UrlRedirect;
use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Panel menüsü rozetleri (faz 38): bekleyen rezervasyon onayı, yeni talep,
 * başarısız bildirim, bekleyen KYC, okunmamış bildirim. Hepsi TEK sorguda
 * (alt sorgu başına count) — her sayfa isteğinde rozet başına sorgu açılmaz
 * (QueryBudgetTest). Hangi rozetlerin sayılacağına çağıran karar verir
 * (izne göre); yetkisiz sayaç için alt sorgu bile kurulmaz.
 *
 * Tenant kapsamı: rezervasyon/KYC alt sorguları ilgili servislerden gelir
 * (BookingService::pendingQuery, KycQueueService::queueQuery); bu sınıf
 * tenant scope baypası yapmaz.
 */
class PanelBadgeService
{
    public const KEYS = ['unread', 'bookings_pending', 'leads_new', 'notifications_failed', 'kyc_pending', 'subscriptions_expiring', 'invoices_overdue', 'franchise_new', 'redirects_pending'];

    public function __construct(
        private readonly BookingService $bookings,
        private readonly KycQueueService $kyc,
        private readonly SubscriptionService $subscriptions,
        private readonly InvoiceService $invoices,
        private readonly FranchiseService $franchise,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array<int, string>  $wanted  KEYS alt kümesi
     * @return array<string, int> istenmeyen anahtarlar 0
     */
    public function counts(User $user, array $wanted): array
    {
        $wanted = array_values(array_intersect(self::KEYS, $wanted));
        $out = array_fill_keys(self::KEYS, 0);

        if ($wanted === []) {
            return $out;
        }

        // İstek başına bir kez: layout composer birden çok görünüme bağlanır.
        return $this->context->rememberForRequest('panel:badges:'.$user->id.':'.implode(',', $wanted), function () use ($user, $wanted, $out) {
            $query = DB::query();

            foreach ($wanted as $key) {
                $query->selectSub($this->countQuery($user, $key), $key);
            }

            $row = (array) $query->first();

            foreach ($wanted as $key) {
                $out[$key] = (int) ($row[$key] ?? 0);
            }

            return $out;
        });
    }

    /** count(*) alt sorgusu — tenant/yetki kararı ilgili servisin sorgusunda. */
    private function countQuery(User $user, string $key): QueryBuilder
    {
        $builder = match ($key) {
            'unread' => $user->unreadNotifications()->getQuery()->toBase(),
            'bookings_pending' => $this->bookings->pendingQuery()->toBase(),
            'leads_new' => Lead::query()->where('status', 'new')->toBase(),
            'notifications_failed' => NotificationLog::query()->where('status', 'failed')->toBase(),
            'kyc_pending' => $this->kyc->queueQuery($user)->toBase(),
            'subscriptions_expiring' => $this->subscriptions->expiringQuery()->toBase(),
            'invoices_overdue' => $this->invoices->overdueQuery()->toBase(),
            'franchise_new' => $this->franchise->newQuery()->toBase(),
            // Onay bekleyen yönlendirme önerileri (faz 54) — tüm siteler.
            'redirects_pending' => UrlRedirect::query()->where('status', 'pending')->toBase(),
            default => throw new InvalidArgumentException('Bilinmeyen rozet: '.$key),
        };

        return $builder->selectRaw('count(*)');
    }
}
