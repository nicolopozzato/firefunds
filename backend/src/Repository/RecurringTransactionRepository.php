<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\RecurringTransaction;
use App\Entity\Tag;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<RecurringTransaction>
 */
class RecurringTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecurringTransaction::class);
    }

    /**
     * A user's recurring transactions with their accounts, category and tags
     * in one query, by description.
     *
     * @return list<RecurringTransaction>
     */
    public function findByUser(User $user): array
    {
        return $this->withRelations()
            ->andWhere('r.user = :user')
            ->setParameter('user', $user)
            ->orderBy('LOWER(r.description)')
            ->getQuery()
            ->getResult();
    }

    /**
     * Everyone's that are not paused: what the scheduled task looks at.
     *
     * @return list<RecurringTransaction>
     */
    public function findRunning(): array
    {
        return $this->withRelations()
            ->andWhere('r.pausedAt IS NULL')
            ->getQuery()
            ->getResult();
    }

    public function findOneByUserAndId(User $user, string $id): ?RecurringTransaction
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->findOneBy(['user' => $user, 'id' => $id]);
    }

    /** @return list<RecurringTransaction> */
    public function findByAccount(Account $account): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.sourceAccount = :account OR r.destinationAccount = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->getResult();
    }

    /** @return list<RecurringTransaction> */
    public function findByCategory(Category $category): array
    {
        return $this->findBy(['category' => $category]);
    }

    /** @return list<RecurringTransaction> */
    public function findByTag(Tag $tag): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.tags', 't')
            ->andWhere('t = :tag')
            ->setParameter('tag', $tag)
            ->getQuery()
            ->getResult();
    }

    private function withRelations(): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('r')
            ->addSelect('s', 'd', 'c', 't', 'u')
            ->join('r.user', 'u')
            ->leftJoin('r.sourceAccount', 's')
            ->leftJoin('r.destinationAccount', 'd')
            ->leftJoin('r.category', 'c')
            ->leftJoin('r.tags', 't');
    }
}
