<?php

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /** The open one for this condition, dismissed or not. */
    public function findOpen(User $user, string $key): ?Notification
    {
        return $this->findOneBy(['user' => $user, 'key' => $key, 'resolvedAt' => null]);
    }

    /**
     * Every open one for these conditions, whoever they are for: a key
     * starting with `$prefix` ("recurring:<id>:" for every day of one).
     *
     * @return list<Notification>
     */
    public function findOpenByKeyPrefix(string $prefix): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.resolvedAt IS NULL')
            ->andWhere('n.key LIKE :prefix')
            ->setParameter('prefix', addcslashes($prefix, '%_\\').'%')
            ->getQuery()
            ->getResult();
    }

    /**
     * What the bell shows: open and not dismissed, newest first.
     *
     * @return list<Notification>
     */
    public function findShown(User $user): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.user = :user')
            ->andWhere('n.resolvedAt IS NULL')
            ->andWhere('n.dismissedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('n.updatedAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();
    }

    /**
     * Open, unread, not dismissed and not pushed yet: what PushDelivery looks at.
     *
     * @return list<Notification>
     */
    public function findToPush(): array
    {
        return $this->createQueryBuilder('n')
            ->addSelect('u')
            ->join('n.user', 'u')
            ->andWhere('n.resolvedAt IS NULL AND n.dismissedAt IS NULL AND n.readAt IS NULL AND n.pushedAt IS NULL')
            ->andWhere('u.disabledAt IS NULL')
            ->orderBy('n.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByUserAndId(User $user, string $id): ?Notification
    {
        return Uuid::isValid($id) ? $this->findOneBy(['user' => $user, 'id' => $id]) : null;
    }
}
