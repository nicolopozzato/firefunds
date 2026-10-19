<?php

namespace App\Tests\Integration\Service;

use App\Account\Service\AccountService;
use App\Dashboard\DTO\DashboardResponse;
use App\Dashboard\Service\DashboardService;
use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\Category;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentInstrumentType;
use App\Entity\InvestmentPrice;
use App\Entity\InvestmentTransaction;
use App\Entity\InvestmentTransactionType;
use App\Entity\Transaction;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class DashboardServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private DashboardService $service;
    private User $user;
    private Account $checking;
    private Account $broker;
    private Account $supermarket;
    private Account $employer;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->service = $container->get(DashboardService::class);

        $this->user = new User();
        $this->user->setFirstName('Dashboard');
        $this->user->setLastName('Test');
        $this->user->setEmail(\sprintf('dashboard-test-%s@example.test', uniqid()));
        $this->user->setPassword('hashed');
        $this->user->setTimezone('Europe/Rome');
        $this->entityManager->persist($this->user);

        $this->checking = $this->assetAccount('Checking', AssetAccountKind::Checking, '1000.0000');
        $this->broker = $this->assetAccount('Broker', AssetAccountKind::Investment, '0.0000');
        $this->supermarket = $this->account('Supermarket', AccountType::Expense);
        $this->employer = $this->account('Employer', AccountType::Revenue);
    }

    /**
     * A month with a salary, two expenses, money moved to the broker and an
     * ETF bought there: income and expenses leave the transfer and the trade
     * out, the net worth counts what is held at its market value.
     */
    public function testPeriodFigures(): void
    {
        $this->seedSeptember();

        $dashboard = $this->dashboard('2026-09-01', '2026-09-30');
        $summary = $dashboard->summary;

        $this->assertSame('2000.0000', $summary->income);
        $this->assertSame('400.0000', $summary->expenses);
        $this->assertSame('1600.0000', $summary->balance);
        $this->assertSame('80.0000', $summary->savingsRate);
        $this->assertSame('1500.0000', $summary->netWorthStart, 'end of August: 1000 opening + 500 salary');
        $this->assertSame('3188.0000', $summary->netWorthEnd, 'checking 2100 + broker cash 98 + 9 x 110 held');
        $this->assertSame('1688.0000', $summary->netWorthChange);
        $this->assertSame('112.5333', $summary->netWorthChangePercent);
        $this->assertSame('0.0000', $summary->investmentsStart);
        $this->assertSame('990.0000', $summary->investmentsEnd);
        $this->assertSame('902.0000', $summary->investmentContributions, '9 x 100 + 2 fees');
        $this->assertSame('88.0000', $summary->investmentGain);

        $this->assertSame([['Food', '300.0000'], ['Gifts', '100.0000']], $this->entries($dashboard->expensesByCategory));
        $this->assertSame([['Supermarket', '300.0000'], [null, '100.0000']], $this->entries($dashboard->expenseAccounts));
        $this->assertSame([['Employer', '2000.0000']], $this->entries($dashboard->revenueAccounts));
    }

    /** Each shown account: balance at the end of the period, its line and its latest transactions in the period. */
    public function testAccounts(): void
    {
        $this->seedSeptember();

        $accounts = $this->dashboard('2026-09-01', '2026-09-30')->accounts;

        $this->assertSame(['Checking', 'Broker'], array_map(static fn ($a) => $a->name, $accounts), 'in the order the user gave them: creation order until reordered');
        [$checking, $broker] = $accounts;

        $this->assertSame('1088.0000', $broker->balance, 'free cash plus what is held');
        $this->assertSame('2100.0000', $checking->balance);
        $this->assertCount(30, $checking->history);
        $this->assertSame(['2026-09-01', '1500.0000'], [$checking->history[0]->date, $checking->history[0]->value]);
        $this->assertSame(['2026-09-16', '998.0000'], [$broker->history[15]->date, $broker->history[15]->value], 'cash 98 + 9 x 100');

        $this->assertSame(['ETF', 'To broker'], array_map(static fn ($t) => $t->description, $broker->transactions));
        $this->assertSame(
            ['To broker', 'Present', 'Groceries', 'Salary'],
            array_map(static fn ($t) => $t->description, $checking->transactions),
            'newest first, nothing from August or October'
        );
    }

    /** Only active accounts the user chose to show; the figures above still count every account. */
    public function testHiddenAndInactiveAccountsAreLeftOut(): void
    {
        $this->seedSeptember();
        $closed = $this->assetAccount('Closed', AssetAccountKind::Checking, '50.0000');
        $closed->setIsActive(false);
        $this->entityManager->flush();

        self::getContainer()->get(AccountService::class)->setDashboardAccounts($this->user, [(string) $this->checking->getId(), (string) $closed->getId()]);

        $dashboard = $this->dashboard('2026-09-01', '2026-09-30');

        $this->assertSame(['Checking'], array_map(static fn ($a) => $a->name, $dashboard->accounts));
        $this->assertFalse($this->broker->isShownOnDashboard());
        $this->assertSame('3238.0000', $dashboard->summary->netWorthEnd, 'hidden and inactive accounts still count');
    }

    /** How each instrument went: its price against the start of the period, whatever was bought meanwhile. */
    public function testInstruments(): void
    {
        $this->seedSeptember();

        $instruments = $this->dashboard('2026-09-01', '2026-09-30')->instruments;

        $this->assertCount(1, $instruments);
        $etf = $instruments[0];
        $this->assertSame('ETF', $etf->instrumentName);
        $this->assertSame(['0.0000', '990.0000', '902.0000', '88.0000'], [$etf->valueStart, $etf->valueEnd, $etf->contributions, $etf->gain]);
        $this->assertSame('15.7894', $etf->priceChangePercent, '110 against 95 at the end of August');
        $this->assertCount(30, $etf->values);
        $this->assertSame(['0.0000', '900.0000', '990.0000'], [$etf->values[0]->value, $etf->values[15]->value, $etf->values[29]->value]);
        $this->assertCount(30, $etf->performance);
        $this->assertSame(['2026-09-01', '0.0000'], [$etf->performance[0]->date, $etf->performance[0]->value]);
        $this->assertSame(['2026-09-16', '5.2631'], [$etf->performance[15]->date, $etf->performance[15]->value]);
    }

    /** Over years a line gets a point every few days, at most 400, first and last day always in; the figures stay exact. */
    public function testLongPeriodsAreSampled(): void
    {
        $this->seedSeptember();

        $dashboard = $this->dashboard('2024-01-01', '2026-09-30');
        $history = $dashboard->accounts[0]->history;

        $this->assertLessThanOrEqual(400, \count($history));
        $this->assertSame('2024-01-01', $history[0]->date);
        $this->assertSame(['2026-09-30', '2100.0000'], [$history[\count($history) - 1]->date, $history[\count($history) - 1]->value]);
        $this->assertSame('3188.0000', $dashboard->summary->netWorthEnd);
        $this->assertSame('15.7894', $dashboard->instruments[0]->priceChangePercent, 'against its first price, 95 on 31 August 2026');
        $performanceDays = array_map(static fn ($p) => $p->date, $dashboard->instruments[0]->performance);
        $this->assertSame([], array_diff($performanceDays, array_map(static fn ($p) => $p->date, $history)), 'every line on the same days');
        $this->assertSame('2026-09-30', end($performanceDays));
    }

    /** Days are the user's: 23:30 UTC on 31 August is already 1 September in Rome. */
    public function testPeriodFollowsTheUsersTimezone(): void
    {
        $this->transfer($this->checking, $this->supermarket, '10.0000', '2026-08-31 22:30', 'Late night');
        $this->transfer($this->checking, $this->supermarket, '20.0000', '2026-09-30 22:30', 'Already October');

        $dashboard = $this->dashboard('2026-09-01', '2026-09-30');

        $this->assertSame('10.0000', $dashboard->summary->expenses);
        $this->assertSame(['Late night'], array_map(static fn ($t) => $t->description, $dashboard->accounts[0]->transactions));
    }

    public function testNothingYet(): void
    {
        $dashboard = $this->dashboard('2026-09-01', '2026-09-30');

        $this->assertSame('0.0000', $dashboard->summary->income);
        $this->assertNull($dashboard->summary->savingsRate, 'no income to save from');
        $this->assertSame('1000.0000', $dashboard->summary->netWorthEnd);
        $this->assertSame('0.0000', $dashboard->summary->netWorthChange);
        $this->assertSame([], $dashboard->instruments);
        $this->assertSame([], $dashboard->accounts[0]->transactions);
    }

    /**
     * An account opened during the period was worth nothing at its start: its
     * opening balance raises the net worth from its date, and with nothing at
     * the start there is no percentage to give (the "All" period of a user
     * whose first account was opened after their first transaction).
     */
    public function testAccountOpenedDuringThePeriodWasNotThereAtItsStart(): void
    {
        $this->checking->setOpeningBalance('0.0000');
        $card = $this->assetAccount('Card', AssetAccountKind::Checking, '30.0000');
        $card->setOpeningBalanceDate(new \DateTimeImmutable('2026-09-10'));
        $this->entityManager->flush();
        $this->transfer($this->employer, $this->checking, '2000.0000', '2026-09-05 10:00', 'Salary');

        $summary = $this->dashboard('2026-09-01', '2026-09-30')->summary;

        $this->assertSame('0.0000', $summary->netWorthStart);
        $this->assertSame('2030.0000', $summary->netWorthEnd);
        $this->assertNull($summary->netWorthChangePercent);
    }

    /** The number of queries doesn't grow with accounts, instruments or transactions. */
    public function testQueryCountDoesNotDependOnTheNumberOfAccounts(): void
    {
        $this->seedSeptember();
        $small = $this->countQueries(fn () => $this->dashboard('2026-09-01', '2026-09-30'));

        $other = $this->assetAccount('Savings', AssetAccountKind::Checking, '0.0000');
        $this->transfer($this->checking, $other, '10.0000', '2026-09-20 10:00', 'To savings');
        $secondBroker = $this->assetAccount('Second broker', AssetAccountKind::Investment, '1000.0000');
        $bond = $this->instrument('Bond');
        $this->prices($bond, ['2026-09-01' => '50.0000']);
        $this->trade($secondBroker, $bond, InvestmentTransactionType::Buy, '1', '50.0000', '2026-09-02 10:00');

        $dashboard = null;
        $large = $this->countQueries(function () use (&$dashboard) {
            $dashboard = $this->dashboard('2026-09-01', '2026-09-30');
        });

        $this->assertCount(4, $dashboard->accounts);
        $this->assertCount(2, $dashboard->instruments);
        $this->assertSame($small, $large);
    }

    /**
     * August: 500 salary. September: 2000 salary, 300 groceries (Food), a 100
     * present to nobody (Gifts), 1000 moved to the broker and 9 ETF bought
     * there at 100 + 2 fees. October: 50 groceries.
     */
    private function seedSeptember(): void
    {
        $food = $this->category('Food');
        $gifts = $this->category('Gifts');

        $this->transfer($this->employer, $this->checking, '500.0000', '2026-08-20 10:00', 'August salary');
        $this->transfer($this->employer, $this->checking, '2000.0000', '2026-09-05 10:00', 'Salary');
        $this->transfer($this->checking, $this->supermarket, '300.0000', '2026-09-10 10:00', 'Groceries', $food);
        $this->transfer($this->checking, null, '100.0000', '2026-09-12 10:00', 'Present', $gifts);
        $this->transfer($this->checking, $this->broker, '1000.0000', '2026-09-15 10:00', 'To broker');
        $this->transfer($this->checking, $this->supermarket, '50.0000', '2026-10-02 10:00', 'October groceries', $food);

        $etf = $this->instrument('ETF');
        $this->prices($etf, ['2026-08-31' => '95.0000', '2026-09-16' => '100.0000', '2026-09-30' => '110.0000']);
        $this->trade($this->broker, $etf, InvestmentTransactionType::Buy, '9', '100.0000', '2026-09-16 10:00', fees: '2.0000');
    }

    private function dashboard(string $from, string $to): DashboardResponse
    {
        return $this->service->forUser($this->user, new \DateTimeImmutable($from), new \DateTimeImmutable($to));
    }

    /** @return array<array{0: ?string, 1: string}> */
    private function entries(array $entries): array
    {
        return array_map(static fn ($entry) => [$entry->name, $entry->total], $entries);
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

    private function category(string $name): Category
    {
        $category = new Category($this->user, $name);
        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $category;
    }

    private function transfer(?Account $source, ?Account $destination, string $amount, string $occurredAt, string $description, ?Category $category = null): void
    {
        $transaction = new Transaction($this->user, $source, $destination, new \DateTimeImmutable($occurredAt, new \DateTimeZone('UTC')), $amount, 'EUR');
        $transaction->setDescription($description);
        $transaction->setCategory($category);
        $this->entityManager->persist($transaction);
        $this->entityManager->flush();
    }

    private function instrument(string $name): InvestmentInstrument
    {
        $instrument = new InvestmentInstrument($this->user, $name, InvestmentInstrumentType::Etf);
        $this->entityManager->persist($instrument);
        $this->entityManager->flush();

        return $instrument;
    }

    private function trade(Account $account, InvestmentInstrument $instrument, InvestmentTransactionType $type, string $quantity, string $unitPrice, string $occurredAt, string $fees = '0.0000'): void
    {
        $trade = new InvestmentTransaction(
            user: $this->user,
            account: $account,
            instrument: $instrument,
            type: $type,
            quantity: $quantity,
            unitPrice: $unitPrice,
            occurredAt: new \DateTimeImmutable($occurredAt, new \DateTimeZone('UTC')),
        );
        $trade->setDescription($instrument->getName());
        $trade->setFees($fees);
        $this->entityManager->persist($trade);
        $this->entityManager->flush();
    }

    /**
     * @param array<string, string> $prices Y-m-d => price
     */
    private function prices(InvestmentInstrument $instrument, array $prices): void
    {
        foreach ($prices as $date => $price) {
            $this->entityManager->persist(new InvestmentPrice($instrument, new \DateTimeImmutable($date), $price));
        }
        $this->entityManager->flush();
    }

    private function countQueries(callable $callback): int
    {
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();
        $callback();

        return \count($debugData->getData()['default'] ?? []);
    }
}
