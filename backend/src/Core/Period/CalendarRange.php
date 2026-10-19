<?php

namespace App\Core\Period;

use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Entity\User;

/**
 * Reads the calendar days a request asks for (`from` / `to`, Y-m-d), for
 * every endpoint that works day by day (histories, dashboard, portfolio,
 * prices, reports). Only real dates between MIN_YEAR and MAX_YEAR, `from` on
 * or before `to`, at most MAX_YEARS apart: each day costs a row per account,
 * so an unbounded range is an unbounded amount of work.
 *
 * Days come back at midnight in the server's timezone, as `new
 * \DateTimeImmutable('Y-m-d')` always gave them.
 */
final class CalendarRange
{
    public const int MIN_YEAR = 1970;
    public const int MAX_YEAR = 2100;
    public const int MAX_YEARS = 50;

    /**
     * Both days, defaulting to the current month in the user's timezone.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public static function orThisMonth(?string $from, ?string $to, User $user): array
    {
        $today = new \DateTimeImmutable('now', new \DateTimeZone($user->getTimezone()));

        return self::checked(
            null !== $from ? self::day($from) : self::day($today->format('Y-m-01')),
            null !== $to ? self::day($to) : self::day($today->format('Y-m-t')),
        );
    }

    /**
     * Either day may be left out (null).
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    public static function optional(?string $from, ?string $to): array
    {
        $first = null !== $from ? self::day($from) : null;
        $last = null !== $to ? self::day($to) : null;

        if (null !== $first && null !== $last) {
            self::checked($first, $last);
        }

        return [$first, $last];
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public static function checked(\DateTimeImmutable $first, \DateTimeImmutable $last): array
    {
        if ($first > $last) {
            throw new ApiException(ErrorCode::DateRangeInverted);
        }
        if ($first->modify(\sprintf('+%d years', self::MAX_YEARS)) < $last) {
            throw new ApiException(ErrorCode::DateRangeTooLong, ['years' => self::MAX_YEARS]);
        }

        return [$first, $last];
    }

    /** A Y-m-d day that exists and lies within the supported years. */
    public static function day(string $value): \DateTimeImmutable
    {
        $day = 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value)
            : false;

        if (false === $day || $day->format('Y-m-d') !== $value || !self::isSupportedYear((int) $day->format('Y'))) {
            throw new ApiException(ErrorCode::InvalidDateRange);
        }

        return $day;
    }

    public static function isSupportedYear(int $year): bool
    {
        return $year >= self::MIN_YEAR && $year <= self::MAX_YEAR;
    }
}
