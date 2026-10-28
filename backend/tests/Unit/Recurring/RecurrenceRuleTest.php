<?php

namespace App\Tests\Unit\Recurring;

use App\Entity\RecurrenceMonthDay;
use App\Entity\RecurrenceUnit;
use App\Entity\WeekendShift;
use App\Recurring\Schedule\RecurrenceRule;
use PHPUnit\Framework\TestCase;

class RecurrenceRuleTest extends TestCase
{
    public function testEveryMonthOnTheStartDay(): void
    {
        $rule = new RecurrenceRule(new \DateTimeImmutable('2026-01-08'), RecurrenceUnit::Month);

        $this->assertSame(['2026-01-08', '2026-02-08', '2026-03-08'], $this->days($rule, '2026-01-01', '2026-03-31'));
    }

    public function testTheThirtyFirstIsTheLastDayOfShorterMonths(): void
    {
        $rule = new RecurrenceRule(new \DateTimeImmutable('2026-01-31'), RecurrenceUnit::Month);

        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], $this->days($rule, '2026-01-01', '2026-04-30'));
    }

    public function testTheLastDayOfEachMonth(): void
    {
        $rule = new RecurrenceRule(new \DateTimeImmutable('2026-01-30'), RecurrenceUnit::Month, monthDay: RecurrenceMonthDay::LastDay);

        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], $this->days($rule, '2026-01-01', '2026-03-31'));
    }

    public function testTheSecondTuesdayAndTheLastTuesday(): void
    {
        // 2026-01-13 is the second Tuesday of January.
        $second = new RecurrenceRule(new \DateTimeImmutable('2026-01-13'), RecurrenceUnit::Month, monthDay: RecurrenceMonthDay::Weekday);
        $this->assertSame(['2026-01-13', '2026-02-10', '2026-03-10'], $this->days($second, '2026-01-01', '2026-03-31'));

        $last = new RecurrenceRule(new \DateTimeImmutable('2026-01-27'), RecurrenceUnit::Month, monthDay: RecurrenceMonthDay::LastWeekday);
        $this->assertSame(['2026-01-27', '2026-02-24', '2026-03-31'], $this->days($last, '2026-01-01', '2026-03-31'));
    }

    public function testEveryTwoWeeksAndEveryYear(): void
    {
        $fortnight = new RecurrenceRule(new \DateTimeImmutable('2026-01-05'), RecurrenceUnit::Week, interval: 2);
        $this->assertSame(['2026-01-05', '2026-01-19', '2026-02-02'], $this->days($fortnight, '2026-01-01', '2026-02-10'));

        $yearly = new RecurrenceRule(new \DateTimeImmutable('2024-02-29'), RecurrenceUnit::Year);
        $this->assertSame(['2025-02-28', '2026-02-28'], $this->days($yearly, '2025-01-01', '2026-12-31'));
    }

    public function testAWeekendDayMovesToTheWorkingDayBeforeOrAfter(): void
    {
        // 2026-02-08 and 2026-03-08 are Sundays.
        $before = new RecurrenceRule(new \DateTimeImmutable('2026-01-08'), RecurrenceUnit::Month, weekendShift: WeekendShift::Before);
        $this->assertSame(['2026-01-08', '2026-02-06', '2026-03-06'], $this->days($before, '2026-01-01', '2026-03-31'));

        $after = new RecurrenceRule(new \DateTimeImmutable('2026-01-08'), RecurrenceUnit::Month, weekendShift: WeekendShift::After);
        $this->assertSame(['2026-01-08', '2026-02-09', '2026-03-09'], $this->days($after, '2026-01-01', '2026-03-31'));
    }

    public function testOnlySomeMonthsAndAnEnd(): void
    {
        $school = new RecurrenceRule(new \DateTimeImmutable('2026-05-01'), RecurrenceUnit::Month, months: [9, 10, 11, 12, 1, 2, 3, 4, 5, 6]);
        $this->assertSame(['2026-05-01', '2026-06-01', '2026-09-01'], $this->days($school, '2026-05-01', '2026-09-30'));

        $loan = new RecurrenceRule(new \DateTimeImmutable('2026-01-15'), RecurrenceUnit::Month, count: 3);
        $this->assertSame(['2026-01-15', '2026-02-15', '2026-03-15'], $this->days($loan, '2026-01-01', '2026-12-31'));
        $this->assertNull($loan->nextAfter(new \DateTimeImmutable('2026-03-15')));

        $until = new RecurrenceRule(new \DateTimeImmutable('2026-01-15'), RecurrenceUnit::Month, end: new \DateTimeImmutable('2026-02-20'));
        $this->assertSame(['2026-01-15', '2026-02-15'], $this->days($until, '2026-01-01', '2026-12-31'));
    }

    public function testTheNextDayAfterOne(): void
    {
        $rule = new RecurrenceRule(new \DateTimeImmutable('2026-01-08'), RecurrenceUnit::Month);

        $this->assertSame('2026-01-08', $rule->nextAfter(new \DateTimeImmutable('2025-12-31'))?->format('Y-m-d'));
        $this->assertSame('2026-11-08', $rule->nextAfter(new \DateTimeImmutable('2026-10-08'))?->format('Y-m-d'));
    }

    /**
     * @return list<string>
     */
    private function days(RecurrenceRule $rule, string $from, string $to): array
    {
        return array_map(
            static fn (\DateTimeImmutable $day) => $day->format('Y-m-d'),
            $rule->between(new \DateTimeImmutable($from), new \DateTimeImmutable($to)),
        );
    }
}
