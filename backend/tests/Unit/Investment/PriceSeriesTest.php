<?php

namespace App\Tests\Unit\Investment;

use App\Investment\Position\PriceSeries;
use PHPUnit\Framework\TestCase;

class PriceSeriesTest extends TestCase
{
    private const PRICES = ['2026-10-01' => '100.0000', '2026-10-02' => '101.0000', '2026-10-05' => '105.0000'];

    public function testOnOrBeforeTakesTheQuoteOfThatDay(): void
    {
        $this->assertSame('101.0000', PriceSeries::onOrBefore(self::PRICES, '2026-10-02'));
        $this->assertSame('101.0000', PriceSeries::onOrBefore(self::PRICES, '2026-10-04'), 'a day without a quote');
        $this->assertSame('105.0000', PriceSeries::onOrBefore(self::PRICES, '2027-01-01'));
        $this->assertNull(PriceSeries::onOrBefore(self::PRICES, '2026-09-30'));
    }

    public function testBeforeLeavesTheDayItselfOut(): void
    {
        $this->assertSame('100.0000', PriceSeries::before(self::PRICES, '2026-10-02'));
        $this->assertNull(PriceSeries::before(self::PRICES, '2026-10-01'));
    }
}
