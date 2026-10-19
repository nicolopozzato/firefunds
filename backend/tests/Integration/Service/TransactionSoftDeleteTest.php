<?php

namespace App\Tests\Integration\Service;

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
use App\Investment\Service\InvestmentTransactionService;
use App\Investment\Service\OpenPositionService;
use App\Repository\AccountRepository;
use App\Repository\InvestmentTransactionRepository;
use App\Repository\TransactionRepository;
use App\Transaction\Service\TransactionService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Deleting a money or investment transaction marks it as deleted (keeping the
 * row and its audit history) instead of removing it, and every read path
 * behaves as if it were gone.
 */
class TransactionSoftDeleteTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private TransactionRepository $transactionRepository;
    private InvestmentTransactionRepository $investmentTransactionRepository;
    private AccountRepository $accountRepository;
    private User $user;
    private Account $checking;
    private Account $expense;
    private Account $broker;
    private InvestmentInstrument $instrument;
    private Transaction $moneyTransaction;
    private InvestmentTransaction $investmentTransaction;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->transactionRepository = $container->get(TransactionRepository::class);
        $this->investmentTransactionRepository = $container->get(InvestmentTransactionRepository::class);
        $this->accountRepository = $container->get(AccountRepository::class);

        $this->user = new User();
        $this->user->setFirstName('Soft');
        $this->user->setLastName('Delete');
        $this->user->setEmail(\sprintf('softdelete-%s@example.test', uniqid()));
        $this->user->setPassword('hashed');
        $this->entityManager->persist($this->user);

        $currency = $this->entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']);
        if ($currency === null) {
            $currency = new Currency('EUR', 'Euro', '€', 2, true);
            $this->entityManager->persist($currency);
        }

        $this->checking = new Account($this->user, AccountType::Asset, 'Checking');
        $this->checking->setAssetKind(AssetAccountKind::Checking);
        $this->checking->setCurrency($currency);
        $this->checking->setOpeningBalance('1000.0000');
        $this->entityManager->persist($this->checking);

        $this->expense = new Account($this->user, AccountType::Expense, 'Groceries');
        $this->entityManager->persist($this->expense);

        $this->broker = new Account($this->user, AccountType::Asset, 'Broker');
        $this->broker->setAssetKind(AssetAccountKind::Investment);
        $this->broker->setCurrency($currency);
        $this->broker->setOpeningBalance('1000.0000');
        $this->entityManager->persist($this->broker);

        $this->instrument = new InvestmentInstrument($this->user, 'Soft Delete ETF', InvestmentInstrumentType::Etf);
        $this->entityManager->persist($this->instrument);

        $this->moneyTransaction = new Transaction(
            user: $this->user,
            sourceAccount: $this->checking,
            destinationAccount: $this->expense,
            occurredAt: new \DateTimeImmutable('2026-09-20T10:00:00+00:00'),
            amount: '100.0000',
            currency: 'EUR',
        );
        $this->entityManager->persist($this->moneyTransaction);

        $this->investmentTransaction = new InvestmentTransaction(
            user: $this->user,
            account: $this->broker,
            instrument: $this->instrument,
            type: InvestmentTransactionType::Buy,
            quantity: '2',
            unitPrice: '50.0000',
            occurredAt: new \DateTimeImmutable('2026-09-21T10:00:00+00:00'),
        );
        $this->entityManager->persist($this->investmentTransaction);

        $this->entityManager->flush();
    }

    public function testDeletingMoneyTransactionMarksItDeletedAndHidesIt(): void
    {
        $id = $this->moneyTransaction->getId()->toRfc4122();

        self::getContainer()->get(TransactionService::class)->delete($this->moneyTransaction);
        $this->entityManager->clear();

        $this->assertNotNull($this->deletedAt('transaction', $id));
        $this->assertSame(['deleted'], $this->auditActions('transaction_audit_log', 'transaction_id', $id));

        $this->assertNull($this->transactionRepository->findOneByUserAndId($this->user, $id));

        [$items, $total] = $this->transactionRepository->findByUserPaginated($this->user, 'all', null, null, new PaginationParams(1, 50));
        $this->assertSame([[], 0], [$items, $total]);

        [$ids, $total] = $this->transactionRepository->findAllKindIdsPaginated($this->user, null, null, new PaginationParams(1, 50));
        $this->assertSame([[$this->investmentTransaction->getId()->toRfc4122(), 'investment']], $ids);
        $this->assertSame(1, $total);

        $checkingId = $this->checking->getId()->toRfc4122();
        $this->assertSame('1000.0000', $this->accountRepository->getCurrentBalances([$this->checking])[$checkingId]);
        $this->assertArrayNotHasKey($checkingId, $this->accountRepository->getLastActivityDates([$this->checking]));

        $history = $this->accountRepository->getDailyBalanceHistory($this->checking, new \DateTimeImmutable('2026-09-19'), new \DateTimeImmutable('2026-09-21'));
        $this->assertSame(['1000.0000', '1000.0000', '1000.0000'], array_column($history, 'balance'));
    }

    public function testDeletingInvestmentTransactionMarksItDeletedAndHidesIt(): void
    {
        $id = $this->investmentTransaction->getId()->toRfc4122();

        self::getContainer()->get(InvestmentTransactionService::class)->delete($this->investmentTransaction);
        $this->entityManager->clear();

        $this->assertNotNull($this->deletedAt('investment_transaction', $id));
        $this->assertSame(['deleted'], $this->auditActions('investment_transaction_audit_log', 'investment_transaction_id', $id));

        $this->assertNull($this->investmentTransactionRepository->findOneByUserAndId($this->user, $id));

        [$items, $total] = $this->investmentTransactionRepository->findByUserPaginated($this->user, new PaginationParams(1, 50));
        $this->assertSame([[], 0], [$items, $total]);

        [$ids] = $this->transactionRepository->findAllKindIdsPaginated($this->user, null, null, new PaginationParams(1, 50));
        $this->assertSame([[$this->moneyTransaction->getId()->toRfc4122(), 'money']], $ids);

        $this->assertSame([], $this->investmentTransactionRepository->getPositions($this->broker));
        $this->assertSame(
            ['investedValue' => '0.0000', 'costBasis' => '0.0000', 'gainAmount' => '0.0000', 'gainPercent' => '0.0000', 'estimatedTax' => '0.0000'],
            self::getContainer()->get(OpenPositionService::class)->investedSummaries([$this->broker])[$this->broker->getId()->toRfc4122()]
        );

        $brokerId = $this->broker->getId()->toRfc4122();
        $this->assertSame('1000.0000', $this->accountRepository->getCurrentBalances([$this->broker])[$brokerId]);
        $this->assertArrayNotHasKey($brokerId, $this->accountRepository->getLastActivityDates([$this->broker]));

        $this->assertSame([], self::getContainer()->get(OpenPositionService::class)->forAccount(
            $this->broker,
            new \DateTimeImmutable('2026-09-21'),
            new \DateTimeImmutable('2026-09-22'),
        ));
    }

    private function deletedAt(string $table, string $id): ?string
    {
        $value = $this->connection->fetchOne(\sprintf('SELECT deleted_at FROM %s WHERE id = ?', $table), [$id]);

        return $value === false ? null : $value;
    }

    /**
     * @return string[]
     */
    private function auditActions(string $table, string $column, string $id): array
    {
        return $this->connection->fetchFirstColumn(
            \sprintf('SELECT action FROM %s WHERE %s = ? ORDER BY created_at', $table, $column),
            [$id]
        );
    }
}
