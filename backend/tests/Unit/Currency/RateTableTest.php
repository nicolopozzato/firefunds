<?php

namespace App\Tests\Unit\Currency;

use App\Currency\Service\RateTable;
use PHPUnit\Framework\TestCase;

class RateTableTest extends TestCase
{
    private function table(): RateTable
    {
        return new RateTable([
            'USD' => ['2026-10-01' => '1.10000000', '2026-10-03' => '1.20000000'],
            'GBP' => ['2026-10-01' => '0.85000000'],
        ]);
    }

    public function testTheEuroIsTheMeasureAndNeedsNoRate(): void
    {
        $this->assertSame('1', $this->table()->perEuro('EUR', '2026-10-01'));
        $this->assertSame('110.0000000000', $this->table()->convert('100', 'EUR', 'USD', '2026-10-01'));
        $this->assertSame('90.9090909091', $this->table()->convert('100', 'USD', 'EUR', '2026-10-01'));
    }

    public function testAnyPairIsADivisionThroughTheEuro(): void
    {
        // 100 USD = 100 / 1.10 EUR = 90.909... EUR = 90.909... x 0.85 = 77.27 GBP
        $this->assertSame('77.2727', bcadd($this->table()->convert('100', 'USD', 'GBP', '2026-10-01') ?? '0', '0', 4));
        $this->assertSame('1', $this->table()->rate('USD', 'USD', '2026-10-01'));
    }

    public function testADayWithoutARateTakesTheLatestBeforeItAndBeforeTheFirstTheFirst(): void
    {
        $table = $this->table();

        $this->assertSame('1.10000000', $table->perEuro('USD', '2026-10-02'), 'carried over');
        $this->assertSame('1.20000000', $table->perEuro('USD', '2026-12-31'), 'after the last');
        $this->assertSame('1.10000000', $table->perEuro('USD', '2026-01-01'), 'before the first');
    }

    public function testACurrencyWithNoRateCannotBeConverted(): void
    {
        $this->assertNull($this->table()->perEuro('JPY', '2026-10-01'));
        $this->assertNull($this->table()->convert('100', 'JPY', 'EUR', '2026-10-01'));
        $this->assertNull($this->table()->rate('USD', 'JPY', '2026-10-01'));
    }

    public function testASeriesIsConvertedDayByDayAtEachDaysRate(): void
    {
        $series = $this->table()->convertSeries(['2026-10-01' => '110.0000', '2026-10-03' => '120.0000'], 'USD', 'EUR');

        $this->assertSame(['2026-10-01' => '100.0000', '2026-10-03' => '100.0000'], $series);
        $this->assertSame(['2026-10-01' => '5.0000'], $this->table()->convertSeries(['2026-10-01' => '5.0000'], 'EUR', 'EUR'));
    }
}
