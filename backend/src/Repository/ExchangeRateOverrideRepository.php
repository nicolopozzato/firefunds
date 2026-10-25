<?php

namespace App\Repository;

use App\Entity\ExchangeRateOverride;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExchangeRateOverride>
 */
class ExchangeRateOverrideRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExchangeRateOverride::class);
    }

    public function findOneByDay(User $user, string $currency, \DateTimeImmutable $date): ?ExchangeRateOverride
    {
        return $this->findOneBy(['user' => $user, 'currency' => $currency, 'date' => $date]);
    }
}
