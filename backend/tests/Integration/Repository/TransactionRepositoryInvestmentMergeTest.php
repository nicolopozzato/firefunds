<?php

namespace App\Tests\Integration\Repository;

use App\Core\Pagination\PaginationParams;
use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\Currency;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentInstrumentType;
use App\Entity\InvestmentTransaction;
use App\Entity\InvestmentTransactionType;
use App\Entity\Transaction;
use App\Entity\User;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class TransactionRepositoryInvestmentMergeTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private TransactionRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(TransactionRepository::class);
    }

    public function testAllKindsMergesMoneyAndInvestmentTransactionsOrderedByDate(): void
    {
        $user = new User();
        $user->setFirstName('Merge');
        $user->setLastName('Test');
        $user->setEmail(\sprintf('merge-test-%s@example.test', uniqid()));
        $user->setPassword('hashed');
        $this->entityManager->persist($user);

        $currency = $this->entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']);
        if ($currency === null) {
            $currency = new Currency('EUR', 'Euro', '€', 2, true);
            $this->entityManager->persist($currency);
        }

        $checking = new Account($user, AccountType::Asset, 'Checking');
        $checking->setAssetKind(AssetAccountKind::Checking);
        $checking->setCurrency($currency);
        $this->entityManager->persist($checking);

        $revenue = new Account($user, AccountType::Revenue, 'Salary');
        $this->entityManager->persist($revenue);

        $broker = new Account($user, AccountType::Asset, 'Broker');
        $broker->setAssetKind(AssetAccountKind::Investment);
        $broker->setCurrency($currency);
        $broker->setOpeningBalance('10000.0000');
        $this->entityManager->persist($broker);

        $instrument = new InvestmentInstrument($user, 'Merge ETF', InvestmentInstrumentType::Etf);
        $this->entityManager->persist($instrument);

        $moneyTransaction = new Transaction(
            user: $user,
            sourceAccount: $revenue,
            destinationAccount: $checking,
            occurredAt: new \DateTimeImmutable('2026-02-01T00:00:00+00:00'),
            amount: '500.0000',
            currency: 'EUR',
        );
        $this->entityManager->persist($moneyTransaction);

        $investmentTransaction = new InvestmentTransaction(
            user: $user,
            account: $broker,
            instrument: $instrument,
            type: InvestmentTransactionType::Buy,
            quantity: '5',
            unitPrice: '50.0000',
            occurredAt: new \DateTimeImmutable('2026-02-02T00:00:00+00:00'),
        );
        $this->entityManager->persist($investmentTransaction);

        $this->entityManager->flush();

        $params = new PaginationParams(page: 1, pageSize: 10);
        [$rows, $total] = $this->repository->findAllKindIdsPaginated($user, null, null, $params);

        $this->assertSame(2, $total);
        $this->assertCount(2, $rows);
        // Ordered by occurred_at DESC: the investment transaction (Feb 2) comes before the money one (Feb 1).
        $this->assertSame((string) $investmentTransaction->getId(), $rows[0][0]);
        $this->assertSame('investment', $rows[0][1]);
        $this->assertSame((string) $moneyTransaction->getId(), $rows[1][0]);
        $this->assertSame('money', $rows[1][1]);
    }
}
