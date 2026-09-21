<?php

namespace App\Services;

use App\Models\FranchiseApplication;
use App\Models\User;
use App\Webhooks\WebhookDispatcher;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Franchise yönetimi (faz 39e, artifact §14): vitrin başvurusu (KVKK rızası + IP) →
 * panel değerlendirme (durum, atama, iç not; audit izli). Rozet/dashboard: yeni başvuru.
 */
class FranchiseService
{
    public function __construct(private readonly AuditService $audit, private readonly NotificationService $notifications, private readonly WebhookDispatcher $webhooks, private readonly LegalDocumentService $legal) {}

    /**
     * @param  array{name?: string|null, first_name?: string|null, last_name?: string|null, company?: string|null, email: string, phone?: string|null, city: string, district?: string|null, budget?: string|null, experience?: string|null, message?: string|null}  $data
     * @param  array{ip?: string|null}  $consent
     */
    public function apply(array $data, array $consent): FranchiseApplication
    {
        $first = trim((string) ($data['first_name'] ?? ''));
        $last = trim((string) ($data['last_name'] ?? ''));
        $name = trim((string) ($data['name'] ?? '')) ?: trim($first.' '.$last);
        $application = new FranchiseApplication([
            'number' => $this->nextNumber(),
            'name' => $name,
            'first_name' => $this->blank($first) ?? $this->blank(Str::beforeLast($name, ' ')),
            'last_name' => $this->blank($last) ?? $this->blank(Str::afterLast($name, ' ')),
            'company' => $this->blank($data['company'] ?? null),
            'email' => mb_strtolower(trim($data['email'])),
            'phone' => $this->blank($data['phone'] ?? null),
            'city' => trim($data['city']),
            'district' => $this->blank($data['district'] ?? null),
            'budget' => $this->blank($data['budget'] ?? null),
            'experience' => $this->blank($data['experience'] ?? null),
            'message' => $this->blank($data['message'] ?? null),
            'status' => 'new',
            'consented_at' => Carbon::now(),
            'consent_ip' => $consent['ip'] ?? null,
        ]);
        $application->save();
        $this->audit->record(null, 'franchise.applied', 'franchise_application', $application->id, [], ['number' => $application->number, 'city' => $application->city]);
        $this->legal->record('franchise_application', $application->id, $consent); // audit F-07
        $this->webhooks->emit('franchise.applied', ['application_id' => $application->id, 'number' => $application->number, 'name' => $application->name, 'email' => $application->email, 'phone' => $application->phone, 'company' => $application->company, 'city' => $application->city, 'district' => $application->district, 'budget' => $application->budget, 'status' => $application->status]);
        $this->notifications->dispatch('franchise.applied', [
            'number' => $application->number,
            'name' => $application->name,
            'email' => $application->email,
            'phone' => $application->phone,
            'city' => $application->city,
        ], null, 'franchise_application', $application->id);

        return $application;
    }

    /**
     * @param  array{status?: string|null, q?: string|null}  $filters
     * @return LengthAwarePaginator<int, FranchiseApplication>
     */
    public function paginate(array $filters, int $perPage = 30): LengthAwarePaginator
    {
        $q = trim((string) ($filters['q'] ?? ''));

        return FranchiseApplication::query()->with('assignee')
            ->when($filters['status'] ?? null, fn (Builder $b, $s) => $b->where('status', $s))
            ->when($q !== '', fn (Builder $b) => $b->where(fn (Builder $w) => $w->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")->orWhere('city', 'like', "%{$q}%")))
            ->orderByRaw("case when status = 'new' then 0 else 1 end")->orderByDesc('created_at')
            ->paginate($perPage)->withQueryString();
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $rows = FranchiseApplication::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $out = [];

        foreach (array_keys(FranchiseApplication::STATUSES) as $status) {
            $out[$status] = (int) ($rows[$status] ?? 0);
        }

        return $out;
    }

    /**
     * Menü rozeti: yeni başvurular.
     *
     * @return Builder<FranchiseApplication>
     */
    public function newQuery(): Builder
    {
        return FranchiseApplication::query()->where('status', 'new');
    }

    public function update(User $actor, FranchiseApplication $application, string $status, ?int $assignedTo, ?string $internalNote): FranchiseApplication
    {
        if (! isset(FranchiseApplication::STATUSES[$status])) {
            throw new DomainException('Geçersiz başvuru durumu.');
        }

        $before = $application->toArray();
        $application->fill([
            'status' => $status,
            'assigned_to' => $assignedTo,
            'internal_note' => $this->blank($internalNote),
            'handled_at' => $status !== 'new' && $application->handled_at === null ? Carbon::now() : $application->handled_at,
        ])->save();
        $this->audit->record($actor, 'franchise.updated', 'franchise_application', $application->id, $before, $application->toArray());

        return $application;
    }

    /** Başvuru numarası FR-YYYY-000001 (yıl bazlı sıra; tekillik DB'de). */
    private function nextNumber(): string
    {
        $year = Carbon::now()->format('Y');
        $last = (int) FranchiseApplication::query()->where('number', 'like', "FR-{$year}-%")->lockForUpdate()->count();

        do {
            $candidate = sprintf('FR-%s-%06d', $year, ++$last);
        } while (FranchiseApplication::query()->where('number', $candidate)->exists());

        return $candidate;
    }

    private function blank(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
