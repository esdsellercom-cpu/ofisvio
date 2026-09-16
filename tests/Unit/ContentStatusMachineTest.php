<?php

namespace Tests\Unit;

use App\Enums\ContentStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ContentStatusMachineTest extends TestCase
{
    #[Test]
    public function taslak_dogrudan_yayinlanamaz(): void
    {
        // İnceleme atlanamaz: DRAFT -> PUBLISHED tanımlı değil.
        $this->assertFalse(ContentStatus::DRAFT->canTransitionTo(ContentStatus::PUBLISHED));
        $this->assertFalse(ContentStatus::DRAFT->canTransitionTo(ContentStatus::SCHEDULED));
        $this->assertTrue(ContentStatus::DRAFT->canTransitionTo(ContentStatus::IN_REVIEW));
    }

    #[Test]
    public function her_durumdan_taslaga_donus_yolu_vardir(): void
    {
        // Arşiv dahil hiçbir durum çıkmaz sokak değil: metin her zaman taslağa
        // dönüp akışı yeniden geçebilir.
        foreach (ContentStatus::cases() as $status) {
            if ($status === ContentStatus::DRAFT) {
                continue;
            }

            $reaches = $status->canTransitionTo(ContentStatus::DRAFT)
                || ($status->canTransitionTo(ContentStatus::ARCHIVED) && ContentStatus::ARCHIVED->canTransitionTo(ContentStatus::DRAFT));

            $this->assertTrue($reaches, "{$status->value} taslağa dönemiyor.");
        }
    }

    #[Test]
    public function yalnizca_published_canlidir(): void
    {
        foreach (ContentStatus::cases() as $status) {
            $this->assertSame($status === ContentStatus::PUBLISHED, $status->isLive(), $status->value);
        }
    }

    #[Test]
    public function her_durumun_etiketi_var(): void
    {
        foreach (ContentStatus::cases() as $status) {
            $this->assertNotSame('', $status->label());
        }
    }
}
