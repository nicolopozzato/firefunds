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

    public function testFindEnabledReturnsArray(): void
    {
        $result = $this->repository->findEnabled();
        $this->assertIsArray($result);
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

    public function testFindOneByIdReturnsNullForNonExistent(): void
    {
        $result = $this->repository->findOneById('00000000-0000-0000-0000-000000000000');
        $this->assertNull($result);
    }
}
