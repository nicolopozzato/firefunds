<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\AccountAuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccountAuditLog>
 */
class AccountAuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountAuditLog::class);
    }

    /**
     * @return AccountAuditLog[] newest first
     */
    public function findByAccount(Account $account): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.account = :account')
            ->setParameter('account', $account)
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
