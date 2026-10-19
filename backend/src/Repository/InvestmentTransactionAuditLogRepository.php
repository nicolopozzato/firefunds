<?php

namespace App\Repository;

use App\Entity\InvestmentTransaction;
use App\Entity\InvestmentTransactionAuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InvestmentTransactionAuditLog>
 */
class InvestmentTransactionAuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvestmentTransactionAuditLog::class);
    }

    /**
     * @return InvestmentTransactionAuditLog[]
     */
    public function findByInvestmentTransaction(InvestmentTransaction $investmentTransaction): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.investmentTransaction = :investmentTransaction')
            ->setParameter('investmentTransaction', $investmentTransaction)
            ->orderBy('l.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
