<?php

namespace App\Recurring\Schedule;

use App\Entity\RecurrenceMonthDay;
use App\Entity\RecurrenceUnit;
use App\Entity\WeekendShift;

/**
 * When a recurring transaction falls: from `start`, every `interval` units,
 * on the day `monthDay` reads off the start date (months and years), only in
 * `months` when given, moved off weekends as `weekendShift` says, until `end`
 * or `count` times. Days only: the time of day is the recurrence's own.
 */
final readonly class RecurrenceRule
{
    /** Enough for a daily recurrence over a lifetime; stops a rule that can never fall (every month left out). */
    private const MAX_STEPS = 40000;

    /**
     * @param list<int>|null $months 1-12, null for every month
     */
    public function __construct(
        public \DateTimeImmutable $start,
        public RecurrenceUnit $unit,
        public int $interval = 1,
        public RecurrenceMonthDay $monthDay = RecurrenceMonthDay::Day,
        public WeekendShift $weekendShift = WeekendShift::None,
        public ?array $months = null,
        public ?\DateTimeImmutable $end = null,
        public ?int $count = null,
    ) {
    }

    /**
     * The days it falls on from `from` through `to`, in order.
     *
     * @return list<\DateTimeImmutable>
     */
    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $from = self::day($from);
        $to = self::day($to);
        $days = [];
        foreach ($this->days() as $day) {
            // A weekend shift moves a day by two at most: past that nothing earlier can come.
            if ($day > $to) {
                if ($day > $to->modify('+3 days')) {
                    break;
                }
                continue;
            }
            if ($day >= $from) {
                $days[$day->format('Y-m-d')] = $day;
            }
        }
        ksort($days);

        return array_values($days);
    }

    /** The first day it falls on after `after`, null when it never does again. */
    public function nextAfter(\DateTimeImmutable $after): ?\DateTimeImmutable
    {
        $after = self::day($after);
        $next = null;
        foreach ($this->days() as $day) {
            if ($day > $after && ($next === null || $day < $next)) {
                $next = $day;
            }
            if ($next !== null && $day > $next->modify('+3 days')) {
                break;
            }
        }

        return $next;
    }

    /**
     * Every day it falls on, in the order of the periods (a weekend shift may
     * put one a day or two before the previous's), stopping at the end.
     *
     * @return \Generator<\DateTimeImmutable>
     */
    private function days(): \Generator
    {
        $start = self::day($this->start);
        $produced = 0;
        for ($step = 0; $step < self::MAX_STEPS; ++$step) {
            if ($this->count !== null && $produced >= $this->count) {
                return;
            }
            $base = $this->periodDay($start, $step);
            if ($base === null) {
                continue;
            }
            if ($this->months !== null && !\in_array((int) $base->format('n'), $this->months, true)) {
                continue;
            }
            $day = $this->shifted($base);
            if ($this->end !== null && $day > self::day($this->end)) {
                return;
            }
            ++$produced;
            yield $day;
        }
    }

    /** The day of the n-th period, before any weekend shift; null when that month has no such day (a fifth Tuesday). */
    private function periodDay(\DateTimeImmutable $start, int $step): ?\DateTimeImmutable
    {
        $n = $step * max(1, $this->interval);

        return match ($this->unit) {
            RecurrenceUnit::Day => $start->modify("+{$n} days"),
            RecurrenceUnit::Week => $start->modify('+'.($n * 7).' days'),
            RecurrenceUnit::Month => $this->inMonth($start, $n),
            RecurrenceUnit::Year => $this->inMonth($start, $n * 12),
        };
    }

    private function inMonth(\DateTimeImmutable $start, int $monthsLater): ?\DateTimeImmutable
    {
        $first = $start->modify('first day of this month')->modify("+{$monthsLater} months");
        $length = (int) $first->format('t');
        $startDay = (int) $start->format('j');
        $weekday = (int) $start->format('N');

        switch ($this->monthDay) {
            case RecurrenceMonthDay::Day:
                return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min($startDay, $length));
            case RecurrenceMonthDay::LastDay:
                return $first->modify('last day of this month');
            case RecurrenceMonthDay::Weekday:
                $nth = intdiv($startDay - 1, 7);
                $offset = ($weekday - (int) $first->format('N') + 7) % 7;
                $day = 1 + $offset + 7 * $nth;

                return $day <= $length ? $first->modify('+'.($day - 1).' days') : null;
            case RecurrenceMonthDay::LastWeekday:
                $last = $first->modify('last day of this month');
                $back = ((int) $last->format('N') - $weekday + 7) % 7;

                return $last->modify("-{$back} days");
        }
    }

    private function shifted(\DateTimeImmutable $day): \DateTimeImmutable
    {
        $weekday = (int) $day->format('N');
        if ($weekday < 6 || $this->weekendShift === WeekendShift::None) {
            return $day;
        }

        return $this->weekendShift === WeekendShift::Before
            ? $day->modify('-'.($weekday - 5).' days')
            : $day->modify('+'.(8 - $weekday).' days');
    }

    private static function day(\DateTimeImmutable $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value->format('Y-m-d'));
    }
}
