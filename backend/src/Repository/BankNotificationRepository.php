<?php

namespace App\Repository;

use App\Entity\BankNotification;
use App\Entity\NotificationSource;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BankNotification>
 */
class BankNotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankNotification::class);
    }

    public function findOneByDeviceKey(User $user, string $key): ?BankNotification
    {
        return $this->findOneBy(['user' => $user, 'deviceKey' => $key]);
    }

    /** @return list<BankNotification> newest first, with the drafts they became */
    public function findLatest(NotificationSource $source, int $limit = 50): array
    {
        return $this->createQueryBuilder('n')
            ->addSelect('d')
            ->leftJoin('n.draft', 'd')
            ->andWhere('n.source = :source')
            ->setParameter('source', $source)
            ->orderBy('n.postedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The source's latest notifications, only what the rules read: to count
     * what each rule reads lately. Older ones are left out, so the cost of
     * trying the rules stays the same however long the app has been reading.
     *
     * @return list<array{title: string, text: string, postedAt: \DateTimeImmutable}>
     */
    public function findLatestTexts(NotificationSource $source, int $limit): array
    {
        return $this->createQueryBuilder('n')
            ->select('n.title', 'n.text', 'n.postedAt')
            ->andWhere('n.source = :source')
            ->setParameter('source', $source)
            ->orderBy('n.postedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }

    /** @return list<BankNotification> */
    public function findUnread(NotificationSource $source): array
    {
        return $this->findBy(['source' => $source, 'status' => BankNotification::STATUS_UNREAD], ['postedAt' => 'ASC']);
    }
}
