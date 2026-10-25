<?php

namespace App\Repository;

use App\Core\Pagination\PaginationParams;
use App\Core\Pagination\Paginator;
use App\Entity\ScheduledTaskRun;
use App\Entity\TaskRunStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScheduledTaskRun>
 */
class ScheduledTaskRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduledTaskRun::class);
    }

    /**
     * A task's newest runs, newest first.
     *
     * @return ScheduledTaskRun[]
     */
    public function latest(string $task, int $limit): array
    {
        return $this->findBy(['task' => $task], ['startedAt' => 'DESC'], $limit);
    }

    /**
     * One page of a task's runs, newest first, and how many there are.
     *
     * @return array{0: ScheduledTaskRun[], 1: int}
     */
    public function page(string $task, PaginationParams $params): array
    {
        return Paginator::paginate(
            $this->createQueryBuilder('r')->where('r.task = :task')->setParameter('task', $task)->orderBy('r.startedAt', 'DESC'),
            $params,
        );
    }

    /**
     * A task's runs started at or after a moment, newest first.
     *
     * @return ScheduledTaskRun[]
     */
    public function since(string $task, \DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.task = :task')->andWhere('r.startedAt >= :since')
            ->setParameter('task', $task)->setParameter('since', $since)
            ->orderBy('r.startedAt', 'DESC')
            ->getQuery()->getResult();
    }

    /** When a task last finished with something done (success or partial), null if never. */
    public function lastDoneAt(string $task): ?\DateTimeImmutable
    {
        $run = $this->createQueryBuilder('r')
            ->where('r.task = :task')->andWhere('r.status IN (:done)')
            ->setParameter('task', $task)->setParameter('done', [TaskRunStatus::Success, TaskRunStatus::Partial])
            ->orderBy('r.startedAt', 'DESC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        return $run?->getFinishedAt();
    }
}
