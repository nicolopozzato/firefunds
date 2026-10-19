<?php

namespace App\Repository;

use App\Entity\Currency;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Currency>
 */
class CurrencyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Currency::class);
    }

    /**
     * @return Currency[]
     */
    public function findEnabled(): array
    {
        return $this->findBy(['isEnabled' => true], ['code' => 'ASC']);
    }

    /**
     * @return Currency[]
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['code' => 'ASC']);
    }

    public function findOneByCode(string $code): ?Currency
    {
        return $this->findOneBy(['code' => strtoupper($code)]);
    }

    public function findOneById(string $id): ?Currency
    {
        return $this->findOneBy(['id' => $id]);
    }
}
