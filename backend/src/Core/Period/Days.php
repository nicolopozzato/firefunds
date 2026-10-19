<?php

namespace App\Core\Period;

/** Calendar days as Y-m-d strings, for the daily series of the charts. */
final class Days
{
    /** @return string[] every day in [from, to], Y-m-d */
    public static function between(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $days = [];
        for ($day = $from->setTime(0, 0); $day <= $to; $day = $day->modify('+1 day')) {
            $days[] = $day->format('Y-m-d');
        }

        return $days;
    }

    /**
     * At most $max of the days, evenly spaced, first and last always in: a
     * long period (years) gets a point every few days instead of one a day.
     *
     * @param string[] $days
     * @return string[]
     */
    public static function sample(array $days, int $max): array
    {
        $count = \count($days);
        $step = (int) ceil($count / $max);
        if ($step <= 1) {
            return $days;
        }

        $sampled = [];
        for ($index = 0; $index < $count - 1; $index += $step) {
            $sampled[] = $days[$index];
        }
        $sampled[] = $days[$count - 1];

        return $sampled;
    }
}
