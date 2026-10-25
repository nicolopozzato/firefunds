<?php

namespace App\Task\Tasks;

use App\Task\ScheduledTaskInterface;
use App\Task\TaskResult;
use App\Task\TaskRunLog;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** Once a day: the expired refresh tokens (sessions unused for 30 days) and the old runs of the tasks' log. */
#[AsTaggedItem(priority: 10)]
final class CleanupTask implements ScheduledTaskInterface
{
    private const BATCH_SIZE = 1000;

    public function __construct(
        private readonly RefreshTokenManagerInterface $refreshTokens,
        private readonly TaskRunLog $log,
        private readonly ClockInterface $clock,
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
        $details = ['revoked' => $revoked, 'runs' => $runs];

        return $revoked === 0 && $runs === 0 ? TaskResult::idle($details) : TaskResult::success($details);
    }
}
