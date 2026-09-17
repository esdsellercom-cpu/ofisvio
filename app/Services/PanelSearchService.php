<?php

namespace App\Services;

use App\Exceptions\TenantContextException;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Collection;

/**
 * Panel üst çubuğu araması (faz 38): tek kutudan rezervasyon, talep, şirket ve
 * kullanıcı. Her küme yalnız ilgili izinle aranır ve var olan servislerin
 * süzgeçleriyle (BookingService::paginateAll, LeadService::paginate,
 * UserAdminService::paginate, CompanyService::visibleTo) — tenant sınırı ve
 * yetki kararları burada yeniden yazılmaz. Sonuç sayısı küme başına LIMIT.
 */
class PanelSearchService
{
    public const LIMIT = 8;

    public function __construct(
        private readonly Gate $gate,
        private readonly TenantContext $context,
        private readonly BookingService $bookings,
        private readonly LeadService $leads,
        private readonly UserAdminService $users,
        private readonly CompanyService $companies,
    ) {}

    /**
     * @return array{bookings: Collection<int, Booking>|null, leads: Collection<int, Lead>|null, companies: Collection<int, Company>|null, users: Collection<int, User>|null}
     */
    public function search(User $user, string $q): array
    {
        $q = trim($q);
        $gate = $this->gate->forUser($user);
        $empty = ['bookings' => null, 'leads' => null, 'companies' => null, 'users' => null];

        if (mb_strlen($q) < 2) {
            return $empty;
        }

        return [
            'bookings' => $gate->allows('booking.view') ? collect($this->bookings->paginateAll(['q' => $q], self::LIMIT)->items()) : null,
            'leads' => $gate->allows('lead.view') ? collect($this->leads->paginate(['q' => $q], self::LIMIT)->items()) : null,
            'companies' => $this->companiesFor($user, $q),
            'users' => $gate->allows('user.manage') ? collect($this->users->paginate($q, self::LIMIT)->items()) : null,
        ];
    }

    /**
     * Şirketler yalnız aktif organizasyon bağlamında (visibleTo); bağlam yoksa küme yok.
     *
     * @return Collection<int, Company>|null
     */
    private function companiesFor(User $user, string $q): ?Collection
    {
        if ($this->context->activeOrganizationId() === null) {
            return null;
        }

        try {
            $visible = $this->companies->visibleTo($user);
        } catch (TenantContextException) {
            return null;
        }

        $needle = mb_strtolower($q);

        return $visible
            ->filter(fn (Company $c) => str_contains(mb_strtolower($c->legal_name), $needle) || str_contains((string) $c->tax_number, $needle))
            ->take(self::LIMIT)
            ->values();
    }
}
