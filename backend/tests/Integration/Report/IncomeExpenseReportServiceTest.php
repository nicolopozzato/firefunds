<?php

namespace App\Tests\Integration\Report;

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
use App\Report\DTO\CategoryTableResponse;
use App\Report\DTO\IncomeExpenseReportResponse;
use App\Report\Period\ReportRange;
use App\Report\Service\IncomeExpenseReportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class IncomeExpenseReportServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private IncomeExpenseReportService $service;
    private User $user;
    private Account $checking;
    private Account $savings;
    private Account $broker;
    private Account $supermarket;
    private Account $employer;
    private Account $bank;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->service = $container->get(IncomeExpenseReportService::class);

        $this->user = new User();
        $this->user->setFirstName('Report');
        $this->user->setLastName('Test');
        $this->user->setEmail(\sprintf('report-test-%s@example.test', uniqid()));
        $this->user->setPassword('hashed');
        $this->user->setTimezone('Europe/Rome');
        $this->entityManager->persist($this->user);

        $this->checking = $this->assetAccount('Checking', AssetAccountKind::Checking, '1000.0000');
        $this->broker = $this->assetAccount('Broker', AssetAccountKind::Investment, '0.0000');
        $this->savings = $this->assetAccount('Savings', AssetAccountKind::Checking, '0.0000');
        $this->supermarket = $this->account('Supermarket', AccountType::Expense);
        $this->bank = $this->account('Bank', AccountType::Expense);
        $this->employer = $this->account('Employer', AccountType::Revenue);
    }

    /**
     * Income and expenses month by month; money moved to the broker is
     * "invested", not an expense, and money moved between cash accounts
     * isn't counted at all.
     */
    public function testColumnsByMonth(): void
    {
        $this->seed();

        $report = $this->report('2026-09-01', '2026-10-31');

        $this->assertSame(['2026-09', '2026-10'], array_map(static fn ($p) => $p->key, $report->periods));
        [$september, $october] = $report->periods;
        $this->assertSame(['2000.0000', '400.0000', '1600.0000', '1000.0000'], [$september->income, $september->expenses, $september->balance, $september->invested]);
        $this->assertSame(['200.0000', '52.0000', '148.0000', '-50.0000'], [$october->income, $october->expenses, $october->balance, $october->invested]);
        $this->assertFalse($september->partial);

        $summary = $report->summary;
        $this->assertSame(['2200.0000', '452.0000', '1748.0000', '950.0000'], [$summary->income, $summary->expenses, $summary->balance, $summary->invested]);
        $this->assertSame('79.4545', $summary->savingsRate);
        $this->assertSame('1500.0000', $summary->netWorthStart, 'end of August: 1000 opening + 500 salary');
        $this->assertSame('3426.0000', $summary->netWorthEnd, 'checking 2250 + savings 50 + broker cash 46 + 9 x 120 held');
        $this->assertSame('1926.0000', $summary->netWorthChange, 'what was saved, 1748, plus what the market did, 1080 - 902');
        $this->assertSame(2, $summary->months);
        $this->assertNull($report->comparison);
    }

    /** Categories as rows, months as columns: largest first, "no category" last. */
    public function testCategoryTables(): void
    {
        $this->seed();

        $report = $this->report('2026-09-01', '2026-10-31');

        $this->assertSame([
            ['Food', ['300.0000', '50.0000'], '350.0000', '175.0000'],
            ['Gifts', ['100.0000', '0.0000'], '100.0000', '50.0000'],
            ['Fees', ['0.0000', '2.0000'], '2.0000', '1.0000'],
        ], $this->rows($report->expenses));
        $this->assertSame([['400.0000', '52.0000'], '452.0000', '226.0000'], [$report->expenses->totals, $report->expenses->total, $report->expenses->average]);

        $this->assertSame([
            ['Salary', ['2000.0000', '0.0000'], '2000.0000', '1000.0000'],
            [null, ['0.0000', '200.0000'], '200.0000', '100.0000'],
        ], $this->rows($report->income));
        $this->assertNull($report->income->rows[1]->id);
    }

    /** By year, a range of two months is a single partial column. */
    public function testColumnsByYear(): void
    {
        $this->seed();

        $report = $this->report('2026-09-01', '2026-10-31', ReportRange::BY_YEAR);

        $this->assertCount(1, $report->periods);
        $this->assertSame(['2026', true, '2200.0000'], [$report->periods[0]->key, $report->periods[0]->partial, $report->periods[0]->income]);
        $this->assertSame([['Food', ['350.0000'], '350.0000', '175.0000']], \array_slice($this->rows($report->expenses), 0, 1));
    }

    /**
     * Compared with the two months before: their columns and figures, and in
     * the tables what each category was then; a category of those months
     * alone still gets its row.
     */
    public function testComparisonWithThePreviousRange(): void
    {
        $this->seed();
        $this->transfer($this->checking, $this->supermarket, '30.0000', '2026-07-10 10:00', 'Bookshop', $this->category('Books'));

        $report = $this->report('2026-09-01', '2026-10-31', compare: ReportRange::COMPARE_PREVIOUS);
        $comparison = $report->comparison;

        $this->assertNotNull($comparison);
        $this->assertSame(['2026-07-01', '2026-08-31'], [$comparison->from, $comparison->to]);
        $this->assertSame(['2026-07', '2026-08'], array_map(static fn ($p) => $p->key, $comparison->periods));
        $this->assertSame(['500.0000', '30.0000'], [$comparison->summary->income, $comparison->summary->expenses]);
        $this->assertSame(['1000.0000', '1470.0000'], [$comparison->summary->netWorthStart, $comparison->summary->netWorthEnd]);

        $books = $report->expenses->rows[3];
        $this->assertSame(['Books', ['0.0000', '0.0000'], '0.0000', ['30.0000', '0.0000'], '30.0000'], [$books->name, $books->values, $books->total, $books->comparisonValues, $books->comparisonTotal]);
        $this->assertSame(['0.0000', '0.0000'], $report->expenses->rows[0]->comparisonValues, 'no food bought in July and August');
        $this->assertSame('30.0000', $report->expenses->comparisonTotal);
        $this->assertSame('500.0000', $report->income->rows[0]->comparisonTotal);
    }

    public function testComparisonWithLastYear(): void
    {
        $this->seed();

        $comparison = $this->report('2026-09-01', '2026-10-31', compare: ReportRange::COMPARE_LAST_YEAR)->comparison;

        $this->assertSame(['2025-09-01', '2025-10-31'], [$comparison->from, $comparison->to]);
        $this->assertSame('0.0000', $comparison->summary->income);
        $this->assertSame('1000.0000', $comparison->summary->netWorthEnd, 'an opening balance without a date is there from the beginning');
    }

    /** Each asset account at the start and the end; an investment account with what it holds. */
    public function testAccounts(): void
    {
        $this->seed();

        $accounts = $this->report('2026-09-01', '2026-10-31')->accounts;

        $this->assertSame(
            [
                ['Checking', '1500.0000', '2250.0000', '750.0000', null, null],
                ['Broker', '0.0000', '1126.0000', '1126.0000', '0.0000', '1080.0000'],
                ['Savings', '0.0000', '50.0000', '50.0000', null, null],
            ],
            array_map(static fn ($a) => [$a->name, $a->start, $a->end, $a->change, $a->investedStart, $a->investedEnd], $accounts),
        );
    }

    /** An inactive account that held nothing at either end is left out. */
    public function testEmptyInactiveAccountsAreLeftOut(): void
    {
        $closed = $this->assetAccount('Closed', AssetAccountKind::Checking, '0.0000');
        $closed->setIsActive(false);
        $this->entityManager->flush();

        $names = array_map(static fn ($a) => $a->name, $this->report('2026-09-01', '2026-10-31')->accounts);

        $this->assertNotContains('Closed', $names);
    }

    public function testNetWorthLine(): void
    {
        $this->seed();

        $netWorth = $this->report('2026-09-01', '2026-10-31')->netWorth;

        $this->assertCount(61, $netWorth);
        $this->assertSame(['2026-09-01', '1500.0000'], [$netWorth[0]->date, $netWorth[0]->value]);
        $this->assertSame(['2026-10-31', '3426.0000'], [$netWorth[60]->date, $netWorth[60]->value]);
    }

    /** The report and the dashboard count income and expenses the same way. */
    public function testAgreesWithTheDashboard(): void
    {
        $this->seed();

        $summary = $this->report('2026-09-01', '2026-10-31')->summary;
        $dashboard = self::getContainer()->get(DashboardService::class)
            ->forUser($this->user, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-10-31'))
            ->summary;

        $this->assertSame(
            [$dashboard->income, $dashboard->expenses, $dashboard->netWorthStart, $dashboard->netWorthEnd],
            [$summary->income, $summary->expenses, $summary->netWorthStart, $summary->netWorthEnd],
        );
    }

    /** Months are the user's: 22:30 UTC on 31 August is already September in Rome. */
    public function testMonthsFollowTheUsersTimezone(): void
    {
        $this->transfer($this->checking, $this->supermarket, '10.0000', '2026-08-31 22:30', 'Late night');

        $report = $this->report('2026-08-01', '2026-09-30');

        $this->assertSame(['0.0000', '10.0000'], array_map(static fn ($p) => $p->expenses, $report->periods));
    }

    /** Averages divide by the months gone by, not by those still to come. */
    public function testAveragesLeaveOutMonthsToCome(): void
    {
        $this->seed();

        $report = $this->service->forUser(
            $this->user,
            new ReportRange(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31')),
            ReportRange::BY_MONTH,
            null,
            new \DateTimeImmutable('2026-10-15'),
        );

        $this->assertSame(10, $report->summary->months);
        $this->assertCount(12, $report->periods);
        $this->assertSame('35.0000', $report->expenses->rows[0]->average, 'food: 350 over January to October');
    }

    /**
     * August: 500 salary. September: 2000 salary, 300 groceries (Food), a 100
     * present to nobody (Gifts), 1000 moved to the broker, 50 to savings, and
     * 9 ETF bought at 100 + 2 fees. October: 50 groceries, a 200 bonus with
     * no category, 50 back from the broker and a 2 fee paid from it.
     */
    private function seed(): void
    {
        $salary = $this->category('Salary');
        $food = $this->category('Food');
        $gifts = $this->category('Gifts');
        $fees = $this->category('Fees');

        $this->transfer($this->employer, $this->checking, '500.0000', '2026-08-20 10:00', 'August salary', $salary);
        $this->transfer($this->employer, $this->checking, '2000.0000', '2026-09-05 10:00', 'Salary', $salary);
        $this->transfer($this->checking, $this->supermarket, '300.0000', '2026-09-10 10:00', 'Groceries', $food);
        $this->transfer($this->checking, null, '100.0000', '2026-09-12 10:00', 'Present', $gifts);
        $this->transfer($this->checking, $this->broker, '1000.0000', '2026-09-15 10:00', 'To broker');
        $this->transfer($this->checking, $this->savings, '50.0000', '2026-09-20 10:00', 'To savings');
        $this->transfer($this->checking, $this->supermarket, '50.0000', '2026-10-02 10:00', 'October groceries', $food);
        $this->transfer($this->employer, $this->checking, '200.0000', '2026-10-03 10:00', 'Bonus');
        $this->transfer($this->broker, $this->checking, '50.0000', '2026-10-04 10:00', 'From broker');
        $this->transfer($this->broker, $this->bank, '2.0000', '2026-10-05 10:00', 'Stamp duty', $fees);

        $etf = $this->instrument('ETF');
        $this->prices($etf, ['2026-08-31' => '95.0000', '2026-09-16' => '100.0000', '2026-09-30' => '110.0000', '2026-10-30' => '120.0000']);
        $this->trade($this->broker, $etf, InvestmentTransactionType::Buy, '9', '100.0000', '2026-09-16 10:00', fees: '2.0000');
    }

    private function report(string $from, string $to, string $by = ReportRange::BY_MONTH, ?string $compare = null): IncomeExpenseReportResponse
    {
        return $this->service->forUser(
            $this->user,
            new ReportRange(new \DateTimeImmutable($from), new \DateTimeImmutable($to)),
            $by,
            $compare,
            new \DateTimeImmutable('2026-12-01'),
        );
    }

    /** @return array<array{0: ?string, 1: string[], 2: string, 3: string}> */
    private function rows(CategoryTableResponse $table): array
    {
        return array_map(static fn ($row) => [$row->name, $row->values, $row->total, $row->average], $table->rows);
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

    /** @param array<string, string> $prices Y-m-d => price */
    private function prices(InvestmentInstrument $instrument, array $prices): void
    {
        foreach ($prices as $date => $price) {
            $this->entityManager->persist(new InvestmentPrice($instrument, new \DateTimeImmutable($date), $price));
        }
        $this->entityManager->flush();
    }
}
