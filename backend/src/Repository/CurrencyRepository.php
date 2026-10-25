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
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['code' => 'ASC']);
    }

    /** A currency's symbol; null for a code not on the list. */
    public function symbolOf(string $code): ?string
    {
        return $this->findOneByCode($code)?->getSymbol();
    }

    public function findOneByCode(string $code): ?Currency
    {
        return $this->findOneBy(['code' => strtoupper($code)]);
    }
}
