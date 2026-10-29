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

    /** @return list<BankNotification> newest first */
    public function findLatest(NotificationSource $source, int $limit = 50): array
    {
        return $this->findBy(['source' => $source], ['postedAt' => 'DESC'], $limit);
    }

    /** @return list<BankNotification> */
    public function findUnread(NotificationSource $source): array
    {
        return $this->findBy(['source' => $source, 'status' => BankNotification::STATUS_UNREAD], ['postedAt' => 'ASC']);
    }
}
