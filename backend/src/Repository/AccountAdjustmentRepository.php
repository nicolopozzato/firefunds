<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\AccountAdjustment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccountAdjustment>
 */
class AccountAdjustmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountAdjustment::class);
    }

    /**
     * @return AccountAdjustment[] oldest first
     */
    public function findByAccount(Account $account): array
    {
        return $this->findByAccounts([$account])[$account->getId()->toRfc4122()] ?? [];
    }

    /**
     * The adjustments of several accounts in one query.
     *
     * @param Account[] $accounts
     * @return array<string, AccountAdjustment[]> account id => adjustments, oldest first
     */
    public function findByAccounts(array $accounts): array
    {
        if ($accounts === []) {
            return [];
        }

        $adjustments = $this->createQueryBuilder('aa')
            ->andWhere('aa.account IN (:accounts)')
            ->setParameter('accounts', $accounts)
            ->orderBy('aa.date', 'ASC')
            ->addOrderBy('aa.id', 'ASC')
            ->getQuery()
            ->getResult();

        $byAccount = [];
        foreach ($adjustments as $adjustment) {
            $byAccount[$adjustment->getAccount()->getId()->toRfc4122()][] = $adjustment;
        }

        return $byAccount;
    }
}
