<?php

namespace App\Report\Period;

/**
 * The calendar days a report covers, both ends included, and how they split
 * into its columns: one per calendar month or per calendar year. A range
 * needn't start or end on a month's or a year's edge (15 months shown by
 * year, 10 days of October…): the first and last column are then partial.
 */
final readonly class ReportRange
{
    public const string BY_MONTH = 'month';
    public const string BY_YEAR = 'year';

    public const string COMPARE_PREVIOUS = 'previous';
    public const string COMPARE_LAST_YEAR = 'lastYear';

    public \DateTimeImmutable $from;
    public \DateTimeImmutable $to;

    public function __construct(\DateTimeImmutable $from, \DateTimeImmutable $to)
    {
        $this->from = self::day($from);
        $this->to = self::day($to);

        if ($this->from > $this->to) {
            throw new \InvalidArgumentException('A range ends on or after its first day.');
        }
    }

    /** @return ReportColumn[] oldest first */
    public function columns(string $by): array
    {
        $columns = [];
        $start = $this->from;
        while ($start <= $this->to) {
            [$unitStart, $unitEnd] = $by === self::BY_YEAR
                ? [$start->modify('first day of january this year'), $start->modify('last day of december this year')]
                : [$start->modify('first day of this month'), $start->modify('last day of this month')];
            $end = min($unitEnd, $this->to);

            $columns[] = new ReportColumn(
                key: $start->format($by === self::BY_YEAR ? 'Y' : 'Y-m'),
                from: $start,
                to: $end,
                partial: $start != $unitStart || $end != $unitEnd,
            );
            $start = $end->modify('+1 day');
        }

        return $columns;
    }

    /**
     * The range to compare with: the one of the same length right before
     * (whole months stay whole months: a year before a year, a month before a
     * month), or the same days a year earlier.
     */
    public function comparison(string $compare): self
    {
        if ($compare === self::COMPARE_LAST_YEAR) {
            return new self(self::yearBefore($this->from), self::yearBefore($this->to));
        }

        if ($this->isWholeMonths()) {
            $months = \count($this->columns(self::BY_MONTH));

            return new self(
                $this->from->modify(\sprintf('-%d months', $months)),
                $this->from->modify('-1 day'),
            );
        }

        $days = (int) $this->from->diff($this->to)->days + 1;

        return new self($this->from->modify(\sprintf('-%d days', $days)), $this->from->modify('-1 day'));
    }

    /** The day before the first one: the report's "start" values are taken at its end. */
    public function dayBefore(): \DateTimeImmutable
    {
        return $this->from->modify('-1 day');
    }

    /**
     * How many calendar months the range touches up to `today` (at least one):
     * what a monthly average divides by, so months still to come don't
     * lower it.
     */
    public function elapsedMonths(\DateTimeImmutable $today): int
    {
        $today = self::day($today);
        if ($today < $this->from) {
            return 1;
        }

        return \count((new self($this->from, min($this->to, $today)))->columns(self::BY_MONTH));
    }

    private function isWholeMonths(): bool
    {
        return $this->from->format('j') === '1' && $this->to->format('Y-m-d') === $this->to->modify('last day of this month')->format('Y-m-d');
    }

    /** The same day a year before; the last day of a month stays the last (29 February → 28 February). */
    private static function yearBefore(\DateTimeImmutable $day): \DateTimeImmutable
    {
        $firstOfMonth = $day->modify('first day of this month')->modify('-1 year');
        $isLastDay = $day->format('Y-m-d') === $day->modify('last day of this month')->format('Y-m-d');
        $dayOfMonth = $isLastDay ? (int) $firstOfMonth->format('t') : min((int) $day->format('j'), (int) $firstOfMonth->format('t'));

        return $firstOfMonth->setDate((int) $firstOfMonth->format('Y'), (int) $firstOfMonth->format('n'), $dayOfMonth);
    }

    private static function day(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-d'));
    }
}
