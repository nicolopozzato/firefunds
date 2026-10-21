<?php

namespace App;

use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
        #[Autowire(env: 'APP_TIMEZONE')]
        private string $timezone,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // ensure only last missed task is run
            ->add(
                // Shortly after local midnight (APP_TIMEZONE, the same timezone
                // PriceCalendar ends "yesterday" in) so the day that just ended is
                // recorded as soon as possible, then retried a few more times during
                // the day in case a market (e.g. one closing late in a different
                // timezone) wasn't done publishing its close yet. Every run calls the
                // provider once per symbol, since the last few days are always
                // re-checked. The command itself backfills any day(s) it missed
                // entirely (downtime, no connectivity, etc.) since each instrument's
                // last price, so re-running it is always safe/idempotent.
                RecurringMessage::cron('10 0,4,8,12 * * *', new RunCommandMessage('app:investments:fetch-prices'), $this->timezone)
            )
            ->add(
                // Expired refresh tokens: a session left unused for 30 days.
                RecurringMessage::cron('30 3 * * *', new RunCommandMessage('gesdinet:jwt:clear'), $this->timezone)
            )
            ->add(
                // Backups: the administrator sets how often and for how long
                // (Administration → Backups); this only checks, hourly, whether one is due.
                RecurringMessage::cron('5 * * * *', new RunCommandMessage('app:backup:run'), $this->timezone)
            )
        ;
    }
}
