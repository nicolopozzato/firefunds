<?php

namespace App\Repository;

use App\Entity\NotificationSource;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<NotificationSource>
 */
class NotificationSourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationSource::class);
    }

    /**
     * The user's sources with how many notifications each has, unread ones and the latest, newest first.
     *
     * @return list<array{source: NotificationSource, total: int, unread: int, latest: ?string}>
     */
    public function findWithCounts(User $user): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s AS source', 'COUNT(n.id) AS total', "SUM(CASE WHEN n.status = 'unread' THEN 1 ELSE 0 END) AS unread", 'MAX(n.postedAt) AS latest')
            ->leftJoin('App\Entity\BankNotification', 'n', 'WITH', 'n.source = s')
            ->andWhere('s.user = :user')
            ->setParameter('user', $user)
            ->groupBy('s.id')
            ->orderBy('latest', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row) => [
            'source' => $row['source'],
            'total' => (int) $row['total'],
            'unread' => (int) $row['unread'],
            'latest' => $row['latest'] !== null ? (string) $row['latest'] : null,
        ], $rows);
    }

    public function findOneByUserAndPackage(User $user, string $packageName): ?NotificationSource
    {
        return $this->findOneBy(['user' => $user, 'packageName' => $packageName]);
    }

    public function findOneByUserAndId(User $user, string $id): ?NotificationSource
    {
        return Uuid::isValid($id) ? $this->findOneBy(['user' => $user, 'id' => $id]) : null;
    }
}
