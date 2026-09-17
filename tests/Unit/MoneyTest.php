<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Audit P0-3: tutarlar kuruş; giriş ayrıştırma ve gösterim tek yerde. */
class MoneyTest extends TestCase
{
    #[Test]
    public function parse_buyuk_birimi_kurusa_cevirir(): void
    {
        $this->assertSame(119050, Money::parse('1.190,50'));
        $this->assertSame(119050, Money::parse('1190,50'));
        $this->assertSame(119050, Money::parse('1190.50'));
        $this->assertSame(119050, Money::parse('1,190.50'));
        $this->assertSame(119000, Money::parse('1190'));
        $this->assertSame(119000, Money::parse(1190));
        $this->assertSame(119000000, Money::parse('1.190.000')); // Türkçe binlik
        $this->assertSame(119000, Money::parse('1.190'));       // tek nokta + 3 hane = binlik
        $this->assertSame(0, Money::parse(''));
        $this->assertSame(0, Money::parse(null));
        $this->assertSame(0, Money::parse('abc'));
        $this->assertSame(5, Money::parse('0.05'));
    }

    #[Test]
    public function format_major_ve_percent(): void
    {
        $this->assertSame('1.190,50 ₺', Money::format(119050, 'TRY'));
        $this->assertSame('0,00 €', Money::format(0, 'EUR'));
        $this->assertSame('12,34 $', Money::format(1234, 'USD'));
        $this->assertSame('1190.50', Money::major(119050));
        $this->assertSame('0.00', Money::major(null));
        $this->assertSame(20000, Money::percent(100000, 20));   // %20 KDV
        $this->assertSame(1, Money::percent(3, 20));            // 0,6 kuruş → 1 (yarım yukarı)
        $this->assertSame(0, Money::percent(2, 20));            // 0,4 kuruş → 0
    }
}
