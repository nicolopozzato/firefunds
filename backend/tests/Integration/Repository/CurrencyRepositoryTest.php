<?php

namespace App\Tests\Integration\Repository;

use App\Entity\Currency;
use App\Repository\CurrencyRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Doctrine\ORM\EntityManagerInterface;

class CurrencyRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private CurrencyRepository $repository;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $this->entityManager = $kernel->getContainer()
            ->get('doctrine')
            ->getManager();

        $this->repository = $this->entityManager
            ->getRepository(Currency::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    public function testRepositoryExists(): void
    {
        $this->assertInstanceOf(CurrencyRepository::class, $this->repository);
    }

    public function testFindAllOrderedReturnsArray(): void
    {
        $result = $this->repository->findAllOrdered();
        $this->assertIsArray($result);
    }

    public function testFindOneByCodeReturnsNullForNonExistent(): void
    {
        $result = $this->repository->findOneByCode('NONEXISTENT999');
        $this->assertNull($result);
    }

    public function testTheFixedListHasTheCurrenciesWithTheirMinorUnits(): void
    {
        $decimals = [];
        foreach ($this->repository->findAllOrdered() as $currency) {
            $decimals[$currency->getCode()] = $currency->getDecimalPlaces();
        }

        $this->assertGreaterThan(150, \count($decimals));
        $this->assertSame(2, $decimals['EUR']);
        $this->assertSame(2, $decimals['USD']);
        $this->assertSame(0, $decimals['JPY']);
        $this->assertSame(3, $decimals['KWD']);
    }
}
