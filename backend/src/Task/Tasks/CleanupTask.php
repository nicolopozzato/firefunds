<?php

namespace App\Task\Tasks;

use App\Notification\Service\NotificationCenter;
use App\Task\ScheduledTaskInterface;
use App\Task\TaskResult;
use App\Task\TaskRunLog;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** Once a day: the expired refresh tokens (sessions unused for 30 days), the old runs of the tasks' log and the old resolved notifications. */
#[AsTaggedItem(priority: 10)]
final class CleanupTask implements ScheduledTaskInterface
{
    private const BATCH_SIZE = 1000;

    public function __construct(
        private readonly RefreshTokenManagerInterface $refreshTokens,
        private readonly TaskRunLog $log,
        private readonly ClockInterface $clock,
        private readonly NotificationCenter $notifications,
    ) {
    }

    public static function key(): string
    {
        return 'cleanup';
    }

    public function cron(): string
    {
        return '30 3 * * *';
    }

    public function run(): TaskResult
    {
        $revoked = \count($this->refreshTokens->revokeAllInvalidBatch(new \DateTime(), self::BATCH_SIZE));
        $runs = $this->log->prune($this->clock->now());
        $notifications = $this->notifications->prune();
        $details = ['revoked' => $revoked, 'runs' => $runs, 'notifications' => $notifications];

        return $revoked === 0 && $runs === 0 && $notifications === 0 ? TaskResult::idle($details) : TaskResult::success($details);
    }
}
