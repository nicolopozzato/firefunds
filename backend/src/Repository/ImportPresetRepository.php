<?php

namespace App\Repository;

use App\Entity\ImportPreset;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ImportPreset>
 */
class ImportPresetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportPreset::class);
    }

    /** @return list<ImportPreset> by name, with their account */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('a')
            ->leftJoin('p.account', 'a')
            ->andWhere('p.user = :user')
            ->setParameter('user', $user)
            ->orderBy('LOWER(p.name)')
            ->getQuery()
            ->getResult();
    }

    public function findOneByUserAndId(User $user, string $id): ?ImportPreset
    {
        return Uuid::isValid($id) ? $this->findOneBy(['user' => $user, 'id' => $id]) : null;
    }
}
