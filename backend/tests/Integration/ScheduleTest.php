<?php

namespace App\Tests\Integration;

use App\Schedule;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ScheduleTest extends KernelTestCase
{
    /**
     * The price fetch runs shortly after midnight Rome time and is retried
     * every 4 hours until noon, whatever the server's own (UTC) clock says.
     */
    public function testPriceFetchRunsShortlyAfterLocalMidnightAndRetries(): void
    {
        self::bootKernel();
        $schedule = self::getContainer()->get(Schedule::class)->getSchedule();
        [$message] = $schedule->getRecurringMessages();

        $rome = new \DateTimeZone('Europe/Rome');
        $run = new \DateTimeImmutable('2026-09-30 23:00:00', $rome);
        $runs = [];
        for ($i = 0; $i < 5; ++$i) {
            $run = $message->getTrigger()->getNextRunDate($run);
            $runs[] = $run->setTimezone($rome)->format('Y-m-d H:i');
        }

        $this->assertSame(
            ['2026-10-01 00:10', '2026-10-01 04:10', '2026-10-01 08:10', '2026-10-01 12:10', '2026-10-02 00:10'],
            $runs
        );
    }

    /** The backup command is checked every hour; the administrator's settings decide whether a backup is due. */
    public function testBackupsAreCheckedHourly(): void
    {
        self::bootKernel();
        $schedule = self::getContainer()->get(Schedule::class)->getSchedule();
        $message = $schedule->getRecurringMessages()[2];

        $rome = new \DateTimeZone('Europe/Rome');
        $run = new \DateTimeImmutable('2026-10-01 10:00:00', $rome);
        $runs = [];
        for ($i = 0; $i < 3; ++$i) {
            $run = $message->getTrigger()->getNextRunDate($run);
            $runs[] = $run->setTimezone($rome)->format('H:i');
        }

        $this->assertSame(['10:05', '11:05', '12:05'], $runs);
    }
}
