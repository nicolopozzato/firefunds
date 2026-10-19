<?php

namespace App\Tests\Integration\Repository;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\Transaction;
use App\Entity\User;
use App\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The daily balances count an account's opening balance from its date on:
 * an account opened on the 15th had nothing on the 14th.
 */
class AccountRepositoryOpeningBalanceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private AccountRepository $repository;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(AccountRepository::class);

        $this->user = new User();
        $this->user->setFirstName('Opening');
        $this->user->setLastName('Test');
        $this->user->setEmail(\sprintf('opening-test-%s@example.test', uniqid()));
        $this->user->setPassword('hashed');
        $this->user->setTimezone('Europe/Rome');
        $this->entityManager->persist($this->user);
    }

    public function testDatedOpeningBalanceCountsFromItsDate(): void
    {
        $account = $this->account('30.0000', '2026-09-15');

        $this->assertSame(
            ['2026-09-13' => '0.0000', '2026-09-14' => '0.0000', '2026-09-15' => '30.0000', '2026-09-16' => '30.0000'],
            $this->history($account, '2026-09-13', '2026-09-16'),
        );
    }

    public function testOpeningBalanceDatedBeforeTheRangeIsInTheStartingBalance(): void
    {
        $account = $this->account('30.0000', '2026-09-01');

        $this->assertSame(['2026-09-13' => '30.0000', '2026-09-14' => '30.0000'], $this->history($account, '2026-09-13', '2026-09-14'));
    }

    public function testOpeningBalanceDatedAfterTheRangeIsNotThereYet(): void
    {
        $account = $this->account('30.0000', '2026-10-01');

        $this->assertSame(['2026-09-13' => '0.0000', '2026-09-14' => '0.0000'], $this->history($account, '2026-09-13', '2026-09-14'));
    }

    public function testOpeningBalanceWithoutDateCountsFromTheBeginning(): void
    {
        $account = $this->account('30.0000', null);

        $this->assertSame(['2026-09-13' => '30.0000', '2026-09-14' => '30.0000'], $this->history($account, '2026-09-13', '2026-09-14'));
    }

    /** Transactions before the opening date still count on their own day; the opening balance adds up on its date. */
    public function testTransactionsBeforeTheOpeningDateStillCount(): void
    {
        $account = $this->account('30.0000', '2026-09-15');
        $employer = new Account($this->user, AccountType::Revenue, 'Employer');
        $this->entityManager->persist($employer);
        $this->entityManager->persist(new Transaction($this->user, $employer, $account, new \DateTimeImmutable('2026-09-14 10:00'), '100.0000', 'EUR'));
        $this->entityManager->flush();

        $this->assertSame(
            ['2026-09-13' => '0.0000', '2026-09-14' => '100.0000', '2026-09-15' => '130.0000'],
            $this->history($account, '2026-09-13', '2026-09-15'),
        );
    }

    private function account(string $openingBalance, ?string $openingDate): Account
    {
        $account = new Account($this->user, AccountType::Asset, 'Checking');
        $account->setAssetKind(AssetAccountKind::Checking);
        $account->setOpeningBalance($openingBalance);
        $account->setOpeningBalanceDate($openingDate !== null ? new \DateTimeImmutable($openingDate) : null);
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    /** @return array<string, string> Y-m-d => balance */
    private function history(Account $account, string $from, string $to): array
    {
        return $this->repository->getDailyBalanceHistories([$account], new \DateTimeImmutable($from), new \DateTimeImmutable($to))[$account->getId()->toRfc4122()];
    }
}
