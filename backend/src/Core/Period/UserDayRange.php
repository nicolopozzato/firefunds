<?php

namespace App\Core\Period;

use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * Turns a list's `from` / `to` query into the UTC instants the stored
 * timestamps (UTC) are compared with. A plain date is a calendar day in the
 * user's timezone, as on the dashboard: `from` is its first instant, `to` its
 * last. So something on 1 October at 00:00 in Rome (30 September 22:00 UTC)
 * is in October. A full timestamp is taken as it is.
 */
final class UserDayRange
{
    /**
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable} null where not given
     */
    public static function fromRequest(Request $request, User $user): array
    {
        $timezone = new \DateTimeZone($user->getTimezone());

        try {
            return [
                self::instant($request->query->get('from'), $timezone, endOfDay: false),
                self::instant($request->query->get('to'), $timezone, endOfDay: true),
            ];
        } catch (\Exception) {
            throw new ApiException(ErrorCode::InvalidDateRange);
        }
    }

    /**
     * The UTC instants bounding these calendar days in the user's timezone:
     * the first instant of the first one, the last of the last one.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public static function bounds(User $user, \DateTimeImmutable $firstDay, \DateTimeImmutable $lastDay): array
    {
        $timezone = new \DateTimeZone($user->getTimezone());
        $utc = new \DateTimeZone('UTC');

        return [
            (new \DateTimeImmutable($firstDay->format('Y-m-d').' 00:00:00', $timezone))->setTimezone($utc),
            (new \DateTimeImmutable($lastDay->format('Y-m-d').' 23:59:59', $timezone))->setTimezone($utc),
        ];
    }

    private static function instant(?string $value, \DateTimeZone $timezone, bool $endOfDay): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $utc = new \DateTimeZone('UTC');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
            if ($day === false || $day->format('Y-m-d') !== $value) {
                throw new \InvalidArgumentException(\sprintf('"%s" is not a date.', $value));
            }

            return ($endOfDay ? $day->setTime(23, 59, 59, 999999) : $day)->setTimezone($utc);
        }

        return (new \DateTimeImmutable($value))->setTimezone($utc);
    }
}
