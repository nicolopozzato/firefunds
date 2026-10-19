<?php

namespace App\Investment\Service;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Where a day ends for investment prices: in the app's timezone (APP_TIMEZONE,
 * e.g. Europe/Rome), not in the server's UTC clock. Otherwise, between local
 * midnight and the UTC one, "yesterday" would still be the day before.
 */
class PriceCalendar
{
    public function __construct(
        private readonly ClockInterface $clock,
        #[Autowire(env: 'APP_TIMEZONE')]
        private readonly string $timezone,
    ) {
    }

    public function timezone(): \DateTimeZone
    {
        return new \DateTimeZone($this->timezone);
    }

    /**
     * The calendar day a moment falls on in the app's timezone, in the same
     * shape as yesterday().
     */
    public function dayOf(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment->setTimezone($this->timezone())->format('Y-m-d'));
    }

    /**
     * Yesterday's calendar date in the app's timezone, as midnight in PHP's
     * default timezone (UTC): the same shape Doctrine hydrates the `date`
     * column of investment_price into, so the two compare correctly.
     */
    public function yesterday(): \DateTimeImmutable
    {
        $localToday = $this->clock->now()->setTimezone($this->timezone())->format('Y-m-d');

        return (new \DateTimeImmutable($localToday))->modify('-1 day');
    }
}
