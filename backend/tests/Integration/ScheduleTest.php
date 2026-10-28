<?php

namespace App\Tests\Integration;

use App\Schedule;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The scheduler's times, in Rome whatever the server's own (UTC) clock says.
 * Messages in order: the retry, then the tasks as the administrator's page
 * lists them (prices, rates, backup, cleanup).
 */
class ScheduleTest extends KernelTestCase
{
    /** The price fetch runs shortly after midnight Rome time, then every 4 hours until noon. */
    public function testPriceFetchRunsShortlyAfterLocalMidnightAndRetries(): void
    {
        $this->assertSame(
            ['2026-10-01 00:10', '2026-10-01 04:10', '2026-10-01 08:10', '2026-10-01 12:10', '2026-10-02 00:10'],
            $this->nextRuns(1, '2026-09-30 23:00:00', 5, 'Y-m-d H:i'),
        );
    }

    /** The exchange rates are fetched shortly after the prices, a few times a day. */
    public function testExchangeRatesAreFetchedThreeTimesADay(): void
    {
        $this->assertSame(['01 06:20', '01 12:20', '02 00:20', '02 06:20'], $this->nextRuns(2, '2026-10-01 01:00:00', 4, 'd H:i'));
    }

    /** The backup task is checked every hour; the administrator's settings decide whether a backup is due. */
    public function testBackupsAreCheckedHourly(): void
    {
        $this->assertSame(['10:05', '11:05', '12:05'], $this->nextRuns(4, '2026-10-01 10:00:00', 3, 'H:i'));
    }

    /** Recurring transactions are looked at every quarter of an hour, so one is created at most 15 minutes after its time. */
    public function testRecurringTransactionsAreCreatedEveryQuarterOfAnHour(): void
    {
        $this->assertSame(['10:15', '10:30', '10:45'], $this->nextRuns(3, '2026-10-01 10:00:00', 3, 'H:i'));
    }

    /** The retry, every 15 minutes, never at a minute a task runs (0, 5, 10, 15, 20, 30, 45): it would race the run it repeats. */
    public function testFailedRunsAreRetriedEveryQuarterOfAnHourBetweenTheTasks(): void
    {
        $this->assertSame(['10:07', '10:22', '10:37', '10:52'], $this->nextRuns(0, '2026-10-01 10:00:00', 4, 'H:i'));
    }

    /** @return string[] */
    private function nextRuns(int $index, string $from, int $count, string $format): array
    {
        self::bootKernel();
        $message = self::getContainer()->get(Schedule::class)->getSchedule()->getRecurringMessages()[$index];

        $rome = new \DateTimeZone('Europe/Rome');
        $run = new \DateTimeImmutable($from, $rome);
        $runs = [];
        for ($i = 0; $i < $count; ++$i) {
            $run = $message->getTrigger()->getNextRunDate($run);
            $runs[] = $run->setTimezone($rome)->format($format);
        }

        return $runs;
    }
}
