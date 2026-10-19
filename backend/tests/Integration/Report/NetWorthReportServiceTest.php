<?php

namespace App\Tests\Integration\Report;

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
use App\Report\DTO\NetWorthReportResponse;
use App\Report\DTO\NetWorthRowResponse;
use App\Report\Service\NetWorthReportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class NetWorthReportServiceTest extends KernelTestCase
{
    private const array TO_COME = [null, null, null, null, null, null, null];

    private EntityManagerInterface $entityManager;
    private NetWorthReportService $service;
    private User $user;
    private Account $checking;
    private Account $broker;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->service = $container->get(NetWorthReportService::class);

        $this->user = new User();
        $this->user->setFirstName('Net worth');
        $this->user->setLastName('Test');
        $this->user->setEmail(\sprintf('net-worth-test-%s@example.test', uniqid()));
        $this->user->setPassword('hashed');
        $this->user->setTimezone('Europe/Rome');
        $this->entityManager->persist($this->user);

        $this->checking = $this->assetAccount('Checking', AssetAccountKind::Checking, '1000.0000');
        $this->broker = $this->assetAccount('Broker', AssetAccountKind::Investment, '0.0000');
    }

    /**
     * Each account's cash and each instrument at the end of every month; the
     * current month today, the months to come empty.
     */
    public function testValuesAtTheEndOfEachMonth(): void
    {
        $this->seed();

        $report = $this->report(2026);

        $this->assertSame([2026, 2025, 2026], [$report->year, $report->firstYear, $report->currentYear]);
        $this->assertSame(['2026-01', '2026-01-31'], [$report->months[0]->month, $report->months[0]->date]);
        $this->assertSame('2026-05-15', $report->months[4]->date, 'the current month: today');
        $this->assertNull($report->months[5]->date);

        $this->assertSame(['Checking', 'Broker'], array_map(static fn ($row) => $row->name, $report->cash));
        $this->assertSame(['3500.0000', '3200.0000', '2200.0000', '2200.0000', '2200.0000', ...self::TO_COME], $report->cash[0]->values);
        $this->assertSame(['0.0000', '0.0000', '98.0000', '98.0000', '98.0000', ...self::TO_COME], $report->cash[1]->values, 'the broker\'s free cash: 1000 - 9 x 100 - 2');
        $this->assertSame(['ETF'], array_map(static fn ($row) => $row->name, $report->investments));
        $this->assertSame(['0.0000', '0.0000', '990.0000', '1080.0000', '1080.0000', ...self::TO_COME], $report->investments[0]->values);
        $this->assertSame(['3500.0000', '3200.0000', '3288.0000', '3378.0000', '3378.0000', ...self::TO_COME], $report->total->values);

        $this->assertSame([null, null, '110.0000', '120.0000', '120.0000', ...self::TO_COME], $report->prices[0]->values, 'no price before March');
    }

    /** The year before comes along, with the end of the year before it to start from. */
    public function testTheYearBefore(): void
    {
        $this->seed();

        $total = $this->report(2026)->total;

        $this->assertSame('1000.0000', $total->previousOpening);
        $this->assertSame([...array_fill(0, 11, '1000.0000'), '1500.0000'], $total->previousValues);
    }

    /** Each month's change is what was saved plus what the market did. */
    public function testChangeSplitIntoSavingAndMarket(): void
    {
        $this->seed();

        $report = $this->report(2026);

        $this->assertSame(['2000.0000', '-300.0000', '0.0000', '0.0000', '0.0000', ...self::TO_COME], $report->saved->values);
        $this->assertSame(['0.0000', '0.0000', '88.0000', '90.0000', '0.0000', ...self::TO_COME], $report->market->values, 'March: 990 held - 902 paid, fees included');
        $this->assertSame('500.0000', $report->saved->previousValues[11]);

        $previous = $report->total->previousValues[11];
        foreach (\array_slice($report->total->values, 0, 5) as $index => $value) {
            $this->assertSame(bcsub($value, $previous, 4), bcadd($report->saved->values[$index], $report->market->values[$index], 4));
            $previous = $value;
        }
    }

    /** A year still to come is empty, the one before it filled in as far as today. */
    public function testAYearToCome(): void
    {
        $this->seed();

        $report = $this->report(2027);

        $this->assertSame(array_fill(0, 12, null), $report->total->values);
        $this->assertSame('3378.0000', $report->total->previousValues[4]);
        $this->assertNull($report->total->previousValues[5]);
    }

    /** An account empty all along the two years is no row of zeros. */
    public function testEmptyAccountsAreLeftOut(): void
    {
        $this->seed();
        $this->assetAccount('Never used', AssetAccountKind::Checking, '0.0000');

        $this->assertSame(['Checking', 'Broker'], array_map(static fn (NetWorthRowResponse $row) => $row->name, $this->report(2026)->cash));
    }

    /**
     * December 2025: 500 salary. 2026: 2000 salary in January, 300 spent in
     * February, 1000 moved to the broker and 9 ETF bought there at 100 + 2
     * fees on 1-2 March; the ETF closes March at 110 and April at 120.
     */
    private function seed(): void
    {
        $employer = $this->account('Employer', AccountType::Revenue);
        $shop = $this->account('Shop', AccountType::Expense);

        $this->transfer($employer, $this->checking, '500.0000', '2025-12-15 10:00');
        $this->transfer($employer, $this->checking, '2000.0000', '2026-01-10 10:00');
        $this->transfer($this->checking, $shop, '300.0000', '2026-02-05 10:00');
        $this->transfer($this->checking, $this->broker, '1000.0000', '2026-03-01 10:00');

        $etf = new InvestmentInstrument($this->user, 'ETF', InvestmentInstrumentType::Etf);
        $this->entityManager->persist($etf);
        foreach (['2026-03-02' => '100.0000', '2026-03-31' => '110.0000', '2026-04-30' => '120.0000'] as $date => $price) {
            $this->entityManager->persist(new InvestmentPrice($etf, new \DateTimeImmutable($date), $price));
        }
        $trade = new InvestmentTransaction(
            user: $this->user,
            account: $this->broker,
            instrument: $etf,
            type: InvestmentTransactionType::Buy,
            quantity: '9',
            unitPrice: '100.0000',
            occurredAt: new \DateTimeImmutable('2026-03-02 10:00', new \DateTimeZone('UTC')),
        );
        $trade->setFees('2.0000');
        $this->entityManager->persist($trade);
        $this->entityManager->flush();
    }

    private function report(int $year): NetWorthReportResponse
    {
        return $this->service->forUser($this->user, $year, new \DateTimeImmutable('2026-05-15'));
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

    private function transfer(Account $source, Account $destination, string $amount, string $occurredAt): void
    {
        $this->entityManager->persist(new Transaction($this->user, $source, $destination, new \DateTimeImmutable($occurredAt, new \DateTimeZone('UTC')), $amount, 'EUR'));
        $this->entityManager->flush();
    }
}
