<?php

namespace App\Core\Period;

/**
 * Reads when a transaction happened (see App\Entity\OccursLocally). A time
 * without an offset ("2026-10-01T00:30:00") is the clock time in $timezone,
 * kept as written; one with an offset or Z is an instant, shown in $timezone.
 * The result carries $timezone, which the entity stores alongside the time.
 */
final class TransactionMoment
{
    public static function parse(string $value, string $timezone): \DateTimeImmutable
    {
        $zone = new \DateTimeZone($timezone);
        $hasOffset = 1 === preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value);

        return $hasOffset
            ? (new \DateTimeImmutable($value))->setTimezone($zone)
            : new \DateTimeImmutable($value, $zone);
    }
}
