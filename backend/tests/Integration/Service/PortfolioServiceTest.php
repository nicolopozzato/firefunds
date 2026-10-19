<?php

namespace App\Tests\Integration\Service;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentInstrumentType;
use App\Entity\InvestmentPrice;
use App\Entity\InvestmentTransaction;
use App\Entity\InvestmentTransactionType;
use App\Entity\User;
use App\Investment\DTO\PortfolioResponse;
use App\Investment\Service\PortfolioService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class PortfolioServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private PortfolioService $service;
    private User $user;
    private Account $brokerA;
    private Account $brokerB;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->service = $container->get(PortfolioService::class);

        $this->user = new User();
        $this->user->setFirstName('Portfolio');
        $this->user->setLastName('Test');
        $this->user->setEmail(\sprintf('portfolio-test-%s@example.test', uniqid()));
        $this->user->setPassword('hashed');
        $this->entityManager->persist($this->user);

        $this->brokerA = $this->account('Broker A', AssetAccountKind::Investment, '500.0000');
        $this->brokerB = $this->account('Broker B', AssetAccountKind::Investment, '300.0000');
    }

    /**
     * The same ETF on two brokers is one position; each broker keeps its own
     * average cost, and the tax is estimated broker by broker, so B's loss
     * doesn't offset A's gain.
     */
    public function testSameInstrumentOnTwoAccountsIsOnePosition(): void
    {
        $vwce = $this->instrument('VWCE');
        $this->trade($this->brokerA, $vwce, InvestmentTransactionType::Buy, '2', '100.0000', '2026-09-01 10:00', fees: '1.0000');
        $this->trade($this->brokerB, $vwce, InvestmentTransactionType::Buy, '1', '130.0000', '2026-09-10 10:00');
        $this->prices($vwce, ['2026-09-30' => '120.0000']);

        $position = $this->portfolio('2026-10-01', '2026-10-01')->positions[0];

        $this->assertSame('3.000000', $position->quantity);
        $this->assertSame('331.0000', $position->costBasis);
        $this->assertSame('110.3333', $position->averageCost);
        $this->assertSame('360.0000', $position->currentValue);
        $this->assertSame('29.0000', $position->gainAmount);
        $this->assertSame('2026-09-01', $position->openedOn);
        $this->assertSame('10.1400', $position->estimatedTax, '26% of A\'s 39 gain, nothing on B\'s loss');

        $byAccount = [];
        foreach ($position->accounts as $account) {
            $byAccount[$account->accountName] = [$account->quantity, $account->averageCost, $account->currentValue, $account->gainAmount];
        }
        $this->assertSame([
            'Broker A' => ['2.000000', '100.5000', '240.0000', '39.0000'],
            'Broker B' => ['1.000000', '130.0000', '120.0000', '-10.0000'],
        ], $byAccount);
    }

    /**
     * Net worth: every asset account's balance (a broker's free cash included)
     * plus the market value of what is held.
     */
    public function testTotalsAndShareOfNetWorth(): void
    {
        $this->account('Checking', AssetAccountKind::Checking, '1000.0000');
        $vwce = $this->instrument('VWCE');
        $xeon = $this->instrument('XEON');
        $this->trade($this->brokerA, $vwce, InvestmentTransactionType::Buy, '2', '100.0000', '2026-09-01 10:00', fees: '1.0000');
        $this->trade($this->brokerB, $vwce, InvestmentTransactionType::Buy, '1', '130.0000', '2026-09-10 10:00');
        $this->trade($this->brokerA, $xeon, InvestmentTransactionType::Buy, '10', '10.0000', '2026-09-05 10:00');
        $this->prices($vwce, ['2026-09-30' => '120.0000']);
        $this->prices($xeon, ['2026-09-30' => '10.5000']);

        $portfolio = $this->portfolio('2026-10-01', '2026-10-01');

        $this->assertSame('465.0000', $portfolio->marketValue);
        $this->assertSame('431.0000', $portfolio->costBasis);
        $this->assertSame('34.0000', $portfolio->gainAmount);
        $this->assertSame('11.4400', $portfolio->estimatedTax);
        $this->assertSame('369.0000', $portfolio->cash, 'A: 500 - 201 - 100; B: 300 - 130');
        $this->assertSame('1834.0000', $portfolio->netWorth, '1000 + 369 cash + 465 held');
        $this->assertSame('25.3544', $portfolio->netWorthShare);

        $this->assertSame(['VWCE', 'XEON'], array_map(static fn ($p) => $p->instrumentName, $portfolio->positions), 'largest first');
        $this->assertSame(['77.4193', '22.5806'], array_map(static fn ($p) => $p->portfolioShare, $portfolio->positions));
        $this->assertSame(['19.6292', '5.7251'], array_map(static fn ($p) => $p->netWorthShare, $portfolio->positions));
    }

    /** A holding sold within the range is no longer a position, but its value still shows in the history. */
    public function testHistoryIncludesHoldingsClosedInTheRange(): void
    {
        $etf = $this->instrument('ETF');
        $this->trade($this->brokerA, $etf, InvestmentTransactionType::Buy, '1', '50.0000', '2026-10-01 10:00');
        $this->trade($this->brokerA, $etf, InvestmentTransactionType::Sell, '1', '60.0000', '2026-10-02 10:00');
        $this->prices($etf, ['2026-10-01' => '55.0000']);

        $portfolio = $this->portfolio('2026-10-01', '2026-10-02');

        $this->assertSame([], $portfolio->positions);
        $this->assertSame([
            ['2026-10-01', '55.0000', '50.0000'],
            ['2026-10-02', '0.0000', '0.0000'],
        ], array_map(static fn ($p) => [$p->date, $p->value, $p->costBasis], $portfolio->history));
    }

    /** Every month of the range, also empty ones; BUYs count their costs, SELLs are net of them. */
    public function testMonthlyFlows(): void
    {
        $etf = $this->instrument('ETF');
        $this->trade($this->brokerA, $etf, InvestmentTransactionType::Buy, '2', '100.0000', '2026-07-20 10:00');
        $this->trade($this->brokerA, $etf, InvestmentTransactionType::Buy, '1', '100.0000', '2026-09-03 10:00', fees: '2.0000');
        $this->trade($this->brokerB, $etf, InvestmentTransactionType::Buy, '1', '50.0000', '2026-09-20 10:00');
        $this->trade($this->brokerA, $etf, InvestmentTransactionType::Sell, '1', '110.0000', '2026-10-05 10:00', fees: '1.0000');

        $flows = $this->portfolio('2026-08-15', '2026-10-10')->monthlyFlows;

        $this->assertSame([
            ['2026-08', '0.0000', '0.0000', '0.0000'],
            ['2026-09', '152.0000', '0.0000', '152.0000'],
            ['2026-10', '0.0000', '109.0000', '-109.0000'],
        ], array_map(static fn ($f) => [$f->month, $f->bought, $f->sold, $f->net], $flows), 'July is outside the range');
    }

    public function testNothingInvestedYet(): void
    {
        $portfolio = $this->portfolio('2026-10-01', '2026-10-01');

        $this->assertSame('0.0000', $portfolio->marketValue);
        $this->assertSame('800.0000', $portfolio->netWorth);
        $this->assertSame('0.0000', $portfolio->netWorthShare);
        $this->assertSame([], $portfolio->positions);
        $this->assertSame([], $portfolio->history);
    }

    /** Only the user's own accounts count. */
    public function testOtherUsersAccountsAreLeftOut(): void
    {
        $other = new User();
        $other->setFirstName('Other');
        $other->setLastName('Test');
        $other->setEmail(\sprintf('portfolio-other-%s@example.test', uniqid()));
        $other->setPassword('hashed');
        $this->entityManager->persist($other);
        $otherAccount = new Account($other, AccountType::Asset, 'Not mine');
        $otherAccount->setOpeningBalance('9999.0000');
        $this->entityManager->persist($otherAccount);
        $this->entityManager->flush();

        $this->assertSame('800.0000', $this->portfolio('2026-10-01', '2026-10-01')->netWorth);
    }

    /** The number of queries doesn't grow with accounts, instruments or trades. */
    public function testQueryCountDoesNotDependOnTheSizeOfThePortfolio(): void
    {
        $first = $this->instrument('First');
        $this->trade($this->brokerA, $first, InvestmentTransactionType::Buy, '1', '10.0000', '2026-09-01 10:00');
        $this->prices($first, ['2026-09-01' => '10.0000']);

        $small = $this->countQueries(fn () => $this->portfolio('2026-10-01', '2026-10-31'));

        $this->account('Broker C', AssetAccountKind::Investment, '0');
        $this->account('Checking', AssetAccountKind::Checking, '0');
        foreach (['Second', 'Third'] as $name) {
            $instrument = $this->instrument($name);
            $this->prices($instrument, ['2026-09-01' => '10.0000']);
            $this->trade($this->brokerA, $instrument, InvestmentTransactionType::Buy, '1', '10.0000', '2026-09-01 10:00');
            $this->trade($this->brokerB, $instrument, InvestmentTransactionType::Buy, '1', '10.0000', '2026-09-01 10:00');
        }

        $portfolio = null;
        $large = $this->countQueries(function () use (&$portfolio) {
            $portfolio = $this->portfolio('2026-10-01', '2026-10-31');
        });

        $this->assertCount(3, $portfolio->positions);
        $this->assertSame($small, $large);
    }

    private function portfolio(string $from, string $to): PortfolioResponse
    {
        return $this->service->forUser($this->user, new \DateTimeImmutable($from), new \DateTimeImmutable($to));
    }

    private function account(string $name, AssetAccountKind $kind, string $openingBalance): Account
    {
        $account = new Account($this->user, AccountType::Asset, $name);
        $account->setAssetKind($kind);
        $account->setOpeningBalance($openingBalance);
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
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
            occurredAt: new \DateTimeImmutable($occurredAt),
        );
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
