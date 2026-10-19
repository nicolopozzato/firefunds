<?php

namespace App\Repository;

use App\Entity\Transaction;
use App\Entity\TransactionAuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TransactionAuditLog>
 */
class TransactionAuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TransactionAuditLog::class);
    }

    /**
     * @return TransactionAuditLog[]
     */
    public function findByTransaction(Transaction $transaction): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.transaction = :transaction')
            ->setParameter('transaction', $transaction)
            ->orderBy('l.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
