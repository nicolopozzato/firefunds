<?php

namespace App\Repository;

use App\Entity\Draft;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Draft>
 */
class DraftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Draft::class);
    }

    /** @return list<Draft> waiting for the user, newest first */
    public function findPending(User $user): array
    {
        return $this->withRelations()
            ->andWhere('d.user = :user')
            ->andWhere('d.status = :pending')
            ->setParameter('user', $user)
            ->setParameter('pending', Draft::STATUS_PENDING)
            ->orderBy('d.occurredAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** How many wait for the user: the badge on the inbox, polled every minute. */
    public function countPending(User $user): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.user = :user')
            ->andWhere('d.status = :pending')
            ->setParameter('user', $user)
            ->setParameter('pending', Draft::STATUS_PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<Draft> dealt with (recorded, matched, discarded), the latest first */
    public function findRecentlyClosed(User $user, int $limit = 20): array
    {
        return $this->withRelations()
            ->andWhere('d.user = :user')
            ->andWhere('d.status != :pending')
            ->setParameter('user', $user)
            ->setParameter('pending', Draft::STATUS_PENDING)
            ->orderBy('d.closedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findOneByUserAndId(User $user, string $id): ?Draft
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->withRelations()
            ->andWhere('d.user = :user AND d.id = :id')
            ->setParameter('user', $user)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Its accounts and possible duplicate joined, so one deleted since (hidden
     * by the soft-delete filter) comes back as none instead of failing to load.
     */
    private function withRelations(): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('d')
            ->addSelect('s', 'dst', 'dup')
            ->leftJoin('d.sourceAccount', 's')
            ->leftJoin('d.destinationAccount', 'dst')
            ->leftJoin('d.possibleDuplicate', 'dup');
    }
}
