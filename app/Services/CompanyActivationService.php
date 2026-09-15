<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\CompanyStatusTransition;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Şirket aktivasyon state machine'i — V5 bölüm 11.
 *
 * TEK YAZAR KURALI: companies.status kolonuna SADECE bu servis yazar.
 * Başka bir yerde $company->status = ... yapılırsa state machine baypas
 * edilmiş, geçiş denetim izi kaybolmuş olur. Bu kuralı CI'da PHPStan ile
 * zorlamak, yapılacaklar listesindeki mimari testin bir parçasıdır.
 *
 * Her geçiş company_status_transitions'a yazılır ve geçiş ile log aynı
 * transaction içindedir: log'suz geçiş veya geçişsiz log oluşamaz.
 */
class CompanyActivationService
{
    /**
     * @throws DomainException geçiş state machine'de tanımlı değilse
     */
    public function transitionTo(
        Company $company,
        CompanyStatus $target,
        ?User $performedBy = null,
        ?string $reason = null,
    ): Company {
        $current = $company->status;

        if ($current === $target) {
            return $company; // no-op, log şişirme
        }

        if (! $current->canTransitionTo($target)) {
            throw new DomainException(
                "Geçersiz durum geçişi: {$current->value} -> {$target->value}. ".
                'İzinli: '.(implode(', ', array_map(fn ($s) => $s->value, $current->allowedTransitions())) ?: 'yok (terminal durum)')
            );
        }

        return DB::transaction(function () use ($company, $current, $target, $performedBy, $reason) {
            $company->status = $target;
            $company->save();

            CompanyStatusTransition::create([
                'company_id' => $company->id,
                'from_status' => $current,
                'to_status' => $target,
                'performed_by' => $performedBy?->id,
                'reason' => $reason,
                'created_at' => now(),
            ]);

            return $company;
        });
    }

    /**
     * KYC süreci başlatılabilir mi? (REGISTERED -> KYC_PENDING)
     */
    public function beginKyc(Company $company, ?User $performedBy = null): Company
    {
        return $this->transitionTo($company, CompanyStatus::KYC_PENDING, $performedBy, 'KYC süreci başlatıldı');
    }

    /**
     * Şirket kapatma ön koşulları — matristeki not: "state machine + borc/kyc/
     * sozlesme kontrolu".
     *
     * Burada YALNIZCA state machine kontrolü var; borç ve sözleşme kontrolleri
     * ilgili modüller yazıldığında buraya eklenecek. Şu an eksik olduğunu
     * gizlememek için açıkça işaretliyoruz — sessizce "kapatılabilir" demek
     * yanlış olurdu.
     *
     * @return array<string> engelleyen sebepler; boş dizi = kapatılabilir
     */
    public function blockersForClosure(Company $company): array
    {
        $blockers = [];

        if (! $company->status->canTransitionTo(CompanyStatus::TERMINATION_PENDING)
            && ! $company->status->canTransitionTo(CompanyStatus::TERMINATED)) {
            $blockers[] = "Mevcut durumdan ({$company->status->value}) fesih süreci başlatılamaz.";
        }

        // TODO(invoice modülü): ödenmemiş fatura kontrolü
        // TODO(contract modülü): aktif sözleşme kontrolü
        // TODO(address modülü): tahsisli adresin iadesi

        return $blockers;
    }

    /** @return array<CompanyStatusTransition> */
    public function history(Company $company): array
    {
        return $company->statusTransitions()->orderBy('created_at')->get()->all();
    }
}
