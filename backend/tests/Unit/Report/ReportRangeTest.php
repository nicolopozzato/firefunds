<?php

namespace App\Tests\Unit\Report;

use App\Report\Period\ReportColumn;
use App\Report\Period\ReportRange;
use PHPUnit\Framework\TestCase;

class ReportRangeTest extends TestCase
{
    /** 15 months by month: 15 columns; by year: two, the second one partial. */
    public function testColumnsByMonthOrByYear(): void
    {
        $range = $this->range('2025-01-01', '2026-03-31');

        $this->assertCount(15, $range->columns(ReportRange::BY_MONTH));
        $this->assertSame(['2025-01', '2025-02'], array_slice($this->keys($range->columns(ReportRange::BY_MONTH)), 0, 2));
        $this->assertSame('2026-03', $this->keys($range->columns(ReportRange::BY_MONTH))[14]);

        $years = $range->columns(ReportRange::BY_YEAR);
        $this->assertSame(['2025', '2026'], $this->keys($years));
        $this->assertSame([false, true], array_map(static fn (ReportColumn $c) => $c->partial, $years));
        $this->assertSame(['2026-01-01', '2026-03-31'], [$years[1]->from->format('Y-m-d'), $years[1]->to->format('Y-m-d')]);
    }

    /** A range cut mid-month: the first and last column cover only its days. */
    public function testPartialMonths(): void
    {
        $columns = $this->range('2026-01-15', '2026-03-10')->columns(ReportRange::BY_MONTH);

        $this->assertSame(['2026-01', '2026-02', '2026-03'], $this->keys($columns));
        $this->assertSame([true, false, true], array_map(static fn (ReportColumn $c) => $c->partial, $columns));
        $this->assertSame('2026-01-15', $columns[0]->from->format('Y-m-d'));
        $this->assertSame('2026-03-10', $columns[2]->to->format('Y-m-d'));
    }

    /** Whole months compare with as many whole months right before; any other range with as many days. */
    public function testPreviousRange(): void
    {
        $this->assertRange('2025-01-01', '2025-12-31', $this->range('2026-01-01', '2026-12-31')->comparison(ReportRange::COMPARE_PREVIOUS));
        $this->assertRange('2026-02-01', '2026-02-28', $this->range('2026-03-01', '2026-03-31')->comparison(ReportRange::COMPARE_PREVIOUS));
        $this->assertRange('2025-10-01', '2026-09-30', $this->range('2026-10-01', '2027-09-30')->comparison(ReportRange::COMPARE_PREVIOUS));
        $this->assertRange('2026-09-21', '2026-09-30', $this->range('2026-10-01', '2026-10-10')->comparison(ReportRange::COMPARE_PREVIOUS));
    }

    /** The same days a year earlier; a month's last day stays its last day. */
    public function testSameRangeLastYear(): void
    {
        $this->assertRange('2025-01-01', '2025-10-08', $this->range('2026-01-01', '2026-10-08')->comparison(ReportRange::COMPARE_LAST_YEAR));
        $this->assertRange('2023-02-01', '2023-02-28', $this->range('2024-02-01', '2024-02-29')->comparison(ReportRange::COMPARE_LAST_YEAR));
        $this->assertRange('2024-02-01', '2024-02-29', $this->range('2025-02-01', '2025-02-28')->comparison(ReportRange::COMPARE_LAST_YEAR));
    }

    /** Months still to come don't count for the averages. */
    public function testElapsedMonths(): void
    {
        $year = $this->range('2026-01-01', '2026-12-31');

        $this->assertSame(10, $year->elapsedMonths(new \DateTimeImmutable('2026-10-08')));
        $this->assertSame(12, $year->elapsedMonths(new \DateTimeImmutable('2027-03-01')));
        $this->assertSame(1, $year->elapsedMonths(new \DateTimeImmutable('2025-06-01')));
    }

    private function range(string $from, string $to): ReportRange
    {
        return new ReportRange(new \DateTimeImmutable($from), new \DateTimeImmutable($to));
    }

    /** @param ReportColumn[] $columns */
    private function keys(array $columns): array
    {
        return array_map(static fn (ReportColumn $column) => $column->key, $columns);
    }

    private function assertRange(string $from, string $to, ReportRange $range): void
    {
        $this->assertSame([$from, $to], [$range->from->format('Y-m-d'), $range->to->format('Y-m-d')]);
    }
}
