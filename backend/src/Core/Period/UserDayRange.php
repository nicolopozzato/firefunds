<?php

namespace App\Core\Period;

use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * Turns a list's `from` / `to` query into the bounds `occurred_at` is compared
 * with. That column holds the clock time where each transaction happened
 * (App\Entity\OccursLocally), so a plain date is simply that day: `from` its
 * first instant, `to` its last, with no timezone in between. A timestamp with
 * an offset is read in the user's timezone.
 *
 * The bounds are wall-clock values: compare them with `occurred_at`, never
 * with an instant.
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
                self::bound($request->query->get('from'), $timezone, endOfDay: false),
                self::bound($request->query->get('to'), $timezone, endOfDay: true),
            ];
        } catch (\Exception) {
            throw new ApiException(ErrorCode::InvalidDateRange);
        }
    }

    /**
     * The first instant of the first day and the last of the last one.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public static function bounds(\DateTimeImmutable $firstDay, \DateTimeImmutable $lastDay): array
    {
        return [
            new \DateTimeImmutable($firstDay->format('Y-m-d').' 00:00:00'),
            new \DateTimeImmutable($lastDay->format('Y-m-d').' 23:59:59.999999'),
        ];
    }

    private static function bound(?string $value, \DateTimeZone $timezone, bool $endOfDay): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $day = CalendarRange::day($value);

            return $endOfDay ? $day->setTime(23, 59, 59, 999999) : $day;
        }

        $moment = TransactionMoment::parse($value, $timezone->getName());
        if (!CalendarRange::isSupportedYear((int) $moment->format('Y'))) {
            throw new \InvalidArgumentException(\sprintf('"%s" is out of range.', $value));
        }

        return new \DateTimeImmutable($moment->format('Y-m-d H:i:s.u'));
    }
}
