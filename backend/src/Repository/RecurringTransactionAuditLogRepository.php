<?php

namespace App\Repository;

use App\Entity\RecurringTransaction;
use App\Entity\RecurringTransactionAuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecurringTransactionAuditLog>
 */
class RecurringTransactionAuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecurringTransactionAuditLog::class);
    }

    /**
     * @return RecurringTransactionAuditLog[] newest first
     */
    public function findByRecurring(RecurringTransaction $recurring): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.recurring = :recurring')
            ->setParameter('recurring', $recurring)
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
