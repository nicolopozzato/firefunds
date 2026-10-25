<?php

namespace App\Tests\Integration\Report;

use App\Dashboard\Service\DashboardService;
use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentInstrumentType;
use App\Entity\InvestmentPrice;
use App\Entity\InvestmentTransaction;
use App\Entity\InvestmentTransactionType;
use App\Entity\Transaction;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The figures that must agree, over a period with income, an expense, money
 * moved to a broker and a BUY with fees (the rules each one rests on live in
 * TradeCashEffect, TransactionKind and AssetValueHistory): what net worth
 * changed by = what was saved (income - expenses) + what the investments
 * gained (change in value held - money put in, fees and taxes included).
 */
class DashboardNetWorthConsistencyTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private User $user;

    public function testNetWorthChangeIsWhatWasSavedPlusWhatTheInvestmentsGained(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->user = new User();
        $this->user->setFirstName('Consistency');
        $this->user->setLastName('Test');
        $this->user->setEmail(\sprintf('consistency-%s@example.test', uniqid()));
        $this->user->setPassword('hashed');
        $this->user->setTimezone('Europe/Rome');
        $this->entityManager->persist($this->user);

        $checking = $this->assetAccount('Checking', AssetAccountKind::Checking, '1000.0000');
        $broker = $this->assetAccount('Broker', AssetAccountKind::Investment, '0.0000');
        $employer = $this->account('Employer', AccountType::Revenue);
        $shop = $this->account('Shop', AccountType::Expense);

        $this->money($employer, $checking, '500.0000', '2026-03-10 10:00');
        $this->money($checking, $shop, '200.0000', '2026-03-20 10:00');
        $this->money($checking, $broker, '1000.0000', '2026-03-01 10:00');

        $etf = new InvestmentInstrument($this->user, 'ETF', InvestmentInstrumentType::Etf);
        $this->entityManager->persist($etf);
        foreach (['2026-03-02' => '100.0000', '2026-04-30' => '120.0000'] as $date => $price) {
            $this->entityManager->persist(new InvestmentPrice($etf, new \DateTimeImmutable($date), $price));
        }
        $trade = new InvestmentTransaction(
            user: $this->user,
            account: $broker,
            instrument: $etf,
            type: InvestmentTransactionType::Buy,
            quantity: '9',
            unitPrice: '100.0000',
            occurredAt: new \DateTimeImmutable('2026-03-02 10:00', new \DateTimeZone('UTC')),
        );
        $trade->setFees('2.0000');
        $this->entityManager->persist($trade);
        $this->entityManager->flush();

        $summary = self::getContainer()->get(DashboardService::class)
            ->forUser($this->user, new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-04-30'))
            ->summary;

        $this->assertSame('300.0000', $summary->balance, 'income 500 - expenses 200');
        $this->assertSame('178.0000', $summary->investmentGain, '9 x 120 held - (900 + 2 fees) put in');
        $this->assertSame('478.0000', $summary->netWorthChange);
        $this->assertSame(
            $summary->netWorthChange,
            bcadd($summary->balance, $summary->investmentGain, 4),
            'what net worth changed by = what was saved + what the investments gained',
        );
    }

    private function assetAccount(string $name, AssetAccountKind $kind, string $openingBalance): Account
    {
        $account = $this->account($name, AccountType::Asset);
        $account->setAssetKind($kind);
        $account->setOpeningBalance($openingBalance);
        $this->entityManager->flush();

        return $account;
    }

    private function account(string $name, AccountType $type): Account
    {
        $account = new Account($this->user, $type, $name);
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    private function money(Account $source, Account $destination, string $amount, string $occurredAt): void
    {
        $this->entityManager->persist(new Transaction($this->user, $source, $destination, new \DateTimeImmutable($occurredAt, new \DateTimeZone('UTC')), $amount, 'EUR'));
        $this->entityManager->flush();
    }
}
