<?php

namespace App\Repository;

use App\Entity\ApiToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ApiToken>
 */
class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    /** A token that works: known and not revoked. */
    public function findUsable(string $plainToken): ?ApiToken
    {
        return $this->createQueryBuilder('t')
            ->addSelect('u')
            ->join('t.user', 'u')
            ->andWhere('t.tokenHash = :hash')
            ->andWhere('t.revokedAt IS NULL')
            ->setParameter('hash', ApiToken::hash($plainToken))
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<ApiToken> the user's tokens still working, newest first */
    public function findActiveByUser(User $user): array
    {
        return $this->findBy(['user' => $user, 'revokedAt' => null], ['createdAt' => 'DESC']);
    }

    public function findOneByUserAndId(User $user, string $id): ?ApiToken
    {
        return Uuid::isValid($id) ? $this->findOneBy(['user' => $user, 'id' => $id]) : null;
    }
}
