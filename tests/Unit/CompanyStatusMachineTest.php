<?php

namespace Tests\Unit;

use App\Enums\CompanyStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * State machine'in kendi tutarlılığı. Bu testler DB'ye dokunmaz —
 * saf enum mantığıdır ve hızlı çalışır.
 */
class CompanyStatusMachineTest extends TestCase
{
    #[Test]
    public function her_durum_registereddan_ulasilabilir(): void
    {
        $reached = [CompanyStatus::REGISTERED->value => true];
        $queue = [CompanyStatus::REGISTERED];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($current->allowedTransitions() as $next) {
                if (! isset($reached[$next->value])) {
                    $reached[$next->value] = true;
                    $queue[] = $next;
                }
            }
        }

        $unreachable = array_diff(
            array_map(fn (CompanyStatus $s) => $s->value, CompanyStatus::cases()),
            array_keys($reached)
        );

        $this->assertSame([], array_values($unreachable), 'Ulaşılamayan durum state machine hatasıdır.');
    }

    #[Test]
    public function tek_terminal_durum_vardir_ve_o_terminateddir(): void
    {
        $terminals = array_values(array_filter(
            CompanyStatus::cases(),
            fn (CompanyStatus $s) => $s->isTerminal()
        ));

        $this->assertCount(1, $terminals);
        $this->assertSame(CompanyStatus::TERMINATED, $terminals[0]);
    }

    #[Test]
    public function hicbir_durum_kendine_gecis_yapamaz(): void
    {
        foreach (CompanyStatus::cases() as $status) {
            $this->assertFalse(
                $status->canTransitionTo($status),
                "{$status->value} kendine geçiş yapabiliyor — sonsuz döngü ve gereksiz audit kaydı riski."
            );
        }
    }

    #[Test]
    public function her_durumdan_terminatede_bir_yol_vardir(): void
    {
        // Çıkmaz sokak: fesih edilemeyen bir şirket, kapatılamayan bir
        // müşteri hesabı demektir.
        foreach (CompanyStatus::cases() as $status) {
            if ($status === CompanyStatus::TERMINATED) {
                continue;
            }

            $this->assertTrue(
                $this->canReach($status, CompanyStatus::TERMINATED),
                "{$status->value} durumundan TERMINATED'a yol yok."
            );
        }
    }

    #[Test]
    public function kyc_akisi_beklenen_sirayi_izler(): void
    {
        $this->assertTrue(CompanyStatus::REGISTERED->canTransitionTo(CompanyStatus::KYC_PENDING));
        $this->assertTrue(CompanyStatus::KYC_PENDING->canTransitionTo(CompanyStatus::KYC_REVIEW));
        $this->assertTrue(CompanyStatus::KYC_REVIEW->canTransitionTo(CompanyStatus::KYC_APPROVED));

        // Ek bilgi istendiğinde geri dönebilmeli.
        $this->assertTrue(CompanyStatus::KYC_REVIEW->canTransitionTo(CompanyStatus::KYC_PENDING));

        // KYC atlanamaz.
        $this->assertFalse(CompanyStatus::REGISTERED->canTransitionTo(CompanyStatus::ACTIVE));
        $this->assertFalse(CompanyStatus::REGISTERED->canTransitionTo(CompanyStatus::KYC_APPROVED));
        $this->assertFalse(CompanyStatus::KYC_PENDING->canTransitionTo(CompanyStatus::ACTIVE));
    }

    #[Test]
    public function feshedilen_sirket_geri_dondurulemez(): void
    {
        // Fesih sonrası yeniden aktivasyon YENİ bir şirket kaydıdır;
        // denetim izinin kopmaması için.
        $this->assertSame([], CompanyStatus::TERMINATED->allowedTransitions());
    }

    #[Test]
    public function yalnizca_active_operasyoneldir(): void
    {
        foreach (CompanyStatus::cases() as $status) {
            $this->assertSame(
                $status === CompanyStatus::ACTIVE,
                $status->isOperational(),
                "{$status->value} operasyonel sayılıyor."
            );
        }
    }

    private function canReach(CompanyStatus $from, CompanyStatus $target): bool
    {
        $seen = [$from->value => true];
        $queue = [$from];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($current->allowedTransitions() as $next) {
                if ($next === $target) {
                    return true;
                }

                if (! isset($seen[$next->value])) {
                    $seen[$next->value] = true;
                    $queue[] = $next;
                }
            }
        }

        return false;
    }
}
