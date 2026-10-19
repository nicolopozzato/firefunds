<?php

namespace App\Tests\Unit\Service;

use App\Investment\Service\PriceCalendar;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

class PriceCalendarTest extends TestCase
{
    /**
     * 00:30 in Rome on Oct 1 is still Sept 30 in UTC: the day that just ended
     * in Rome (Sept 30) is "yesterday", not Sept 29.
     */
    public function testYesterdayFollowsTheAppTimezoneAfterLocalMidnight(): void
    {
        $calendar = new PriceCalendar(new MockClock('2026-09-30 22:30:00', 'UTC'), 'Europe/Rome');

        $this->assertSame('2026-09-30', $calendar->yesterday()->format('Y-m-d'));
    }

    public function testYesterdayBeforeLocalMidnight(): void
    {
        $calendar = new PriceCalendar(new MockClock('2026-09-30 21:30:00', 'UTC'), 'Europe/Rome');

        $this->assertSame('2026-09-29', $calendar->yesterday()->format('Y-m-d'));
    }

    /** Compared against investment_price dates, which hydrate as UTC midnight. */
    public function testYesterdayIsMidnightInTheDefaultTimezone(): void
    {
        $calendar = new PriceCalendar(new MockClock('2026-09-30 22:30:00', 'UTC'), 'Europe/Rome');

        $this->assertEquals(new \DateTimeImmutable('2026-09-30 00:00:00'), $calendar->yesterday());
    }
}
