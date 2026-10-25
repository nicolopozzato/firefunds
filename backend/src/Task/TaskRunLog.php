<?php

namespace App\Task;

use App\Core\Doctrine\PhysicalDelete;
use App\Entity\ScheduledTaskRun;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Keeps the scheduled tasks' run log from growing without end: a run with
 * nothing to do that ends like the one before it takes that one's place, and
 * runs older than RETENTION_DAYS go (CleanupTask, daily). Rows of the log are
 * system records, not anybody's data: deleting them is what the owner asked
 * for (2026-10-25).
 */
#[PhysicalDelete('The scheduled tasks\' run log is a system record, not user data: the owner asked for repeated idle runs to be merged and for runs older than 90 days to be deleted.')]
class TaskRunLog
{
    public const RETENTION_DAYS = 90;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** A finished run replaces the run right before it when both had nothing to do and ended alike. */
    public function compact(ScheduledTaskRun $run): void
    {
        $previous = $this->entityManager->createQueryBuilder()
            ->select('r')->from(ScheduledTaskRun::class, 'r')
            ->where('r.task = :task')->andWhere('r.id <> :id')->andWhere('r.startedAt <= :startedAt')
            ->setParameter('task', $run->getTask())->setParameter('id', $run->getId(), 'uuid')->setParameter('startedAt', $run->getStartedAt())
            ->orderBy('r.startedAt', 'DESC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        if ($previous instanceof ScheduledTaskRun && $run->repeats($previous)) {
            $run->absorb($previous);
            $this->entityManager->remove($previous);
            $this->entityManager->flush();
        }
    }

    /** Deletes the runs started before the retention: how many. */
    public function prune(\DateTimeImmutable $now): int
    {
        return $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM scheduled_task_run WHERE started_at < ?',
            [$now->modify('-'.self::RETENTION_DAYS.' days')->format('Y-m-d H:i:s')],
        );
    }
}
