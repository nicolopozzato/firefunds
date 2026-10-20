<?php

namespace App\Tests\Integration\Service;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentInstrumentType;
use App\Entity\InvestmentPrice;
use App\Entity\InvestmentPriceSource;
use App\Entity\InvestmentTransaction;
use App\Entity\InvestmentTransactionType;
use App\Entity\User;
use App\Investment\DTO\OpenPositionResponse;
use App\Investment\Service\OpenPositionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class OpenPositionServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private OpenPositionService $service;
    private User $user;
    private Account $broker;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->service = $container->get(OpenPositionService::class);

        $this->user = new User();
        $this->user->setFirstName('Positions');
        $this->user->setLastName('Test');
        $this->user->setEmail(\sprintf('positions-test-%s@example.test', uniqid()));
        $this->user->setPassword('hashed');
        $this->entityManager->persist($this->user);

        $this->broker = new Account($this->user, AccountType::Asset, 'Broker');
        $this->broker->setAssetKind(AssetAccountKind::Investment);
        $this->entityManager->persist($this->broker);

        $this->entityManager->flush();
    }

    /**
     * Bought in September, looking at October: the chart starts at the real
     * value and cost basis of the holding, not at zero.
     */
    public function testHoldingOpenedBeforeTheRangeStartsAtItsRealValue(): void
    {
        $instrument = $this->instrument('ETF');
        $this->trade($instrument, InvestmentTransactionType::Buy, '2', '100.0000', '2026-09-22 10:00');
        $this->prices($instrument, ['2026-09-22' => '100.0000', '2026-09-30' => '110.0000', '2026-10-02' => '112.0000']);

        $history = $this->history($this->onlyPosition('2026-10-01', '2026-10-03'));

        $this->assertSame([
            '2026-10-01' => ['220.0000', '200.0000'], // last price before the range (Sept 30)
            '2026-10-02' => ['224.0000', '200.0000'],
            '2026-10-03' => ['224.0000', '200.0000'], // no price yet: carried forward
        ], $history);
    }

    /** A SELL takes cost basis out at the average cost, so the average itself doesn't move. */
    public function testSellInsideTheRangeReducesQuantityAndCostBasis(): void
    {
        $instrument = $this->instrument('ETF');
        $this->trade($instrument, InvestmentTransactionType::Buy, '4', '100.0000', '2026-09-01 10:00');
        $this->trade($instrument, InvestmentTransactionType::Sell, '1', '120.0000', '2026-10-02 10:00');
        $this->prices($instrument, ['2026-10-01' => '120.0000']);

        $position = $this->onlyPosition('2026-10-01', '2026-10-02');

        $this->assertSame([
            '2026-10-01' => ['480.0000', '400.0000'],
            '2026-10-02' => ['360.0000', '300.0000'],
        ], $this->history($position));
        $this->assertSame('3.000000', $position->quantity);
        $this->assertSame('100.0000', $position->averageCost);
    }

    /** No price recorded at all (e.g. a fund the provider doesn't quote): worth what was paid for it. */
    public function testInstrumentWithoutPricesIsValuedAtItsTradePrice(): void
    {
        $instrument = $this->instrument('Unpriced');
        $this->trade($instrument, InvestmentTransactionType::Buy, '1', '50.0000', '2026-09-01 10:00');

        $position = $this->onlyPosition('2026-10-01', '2026-10-01');

        $this->assertSame(['50.0000', '0.0000'], [$position->currentPrice, $position->gainAmount]);
        $this->assertSame(['2026-10-01' => ['50.0000', '50.0000']], $this->history($position));
    }

    /**
     * The day's price: the recorded one; else, on a day with a trade, that
     * trade's price, even over a price carried over from the day before;
     * else the latest before. A price the user entered counts as recorded.
     */
    public function testTheDaysPriceFollowsRecordedPricesThenTrades(): void
    {
        $instrument = $this->instrument('ETF');
        $this->prices($instrument, ['2026-10-01' => '100.0000']);
        $this->entityManager->persist(new InvestmentPrice($instrument, new \DateTimeImmutable('2026-10-03'), '101.0000', InvestmentPriceSource::Filled));
        $this->trade($instrument, InvestmentTransactionType::Buy, '1', '105.0000', '2026-10-03 10:00');
        $this->trade($instrument, InvestmentTransactionType::Buy, '1', '107.0000', '2026-10-05 10:00');
        $this->entityManager->persist(new InvestmentPrice($instrument, new \DateTimeImmutable('2026-10-05'), '106.0000', InvestmentPriceSource::Manual));
        $this->entityManager->flush();

        $position = $this->onlyPosition('2026-10-03', '2026-10-06');

        $this->assertSame([
            '2026-10-03' => ['105.0000', '105.0000'], // the trade's price, not the carried-over 101
            '2026-10-04' => ['105.0000', '105.0000'],
            '2026-10-05' => ['212.0000', '212.0000'], // the user's 106, not the trade's 107
            '2026-10-06' => ['212.0000', '212.0000'],
        ], $this->history($position));
    }

    /** The number of queries doesn't grow with the number of positions. */
    public function testQueryCountDoesNotDependOnTheNumberOfPositions(): void
    {
        $first = $this->instrument('First');
        $this->trade($first, InvestmentTransactionType::Buy, '1', '10.0000', '2026-09-01 10:00');
        $this->prices($first, ['2026-09-01' => '10.0000']);

        $withOnePosition = $this->countQueries(fn () => $this->service->forAccount($this->broker, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31')));

        foreach (['Second', 'Third', 'Fourth'] as $name) {
            $instrument = $this->instrument($name);
            $this->trade($instrument, InvestmentTransactionType::Buy, '1', '10.0000', '2026-09-01 10:00');
            $this->prices($instrument, ['2026-09-01' => '10.0000']);
        }

        $positions = null;
        $withFourPositions = $this->countQueries(function () use (&$positions) {
            $positions = $this->service->forAccount($this->broker, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'));
        });

        $this->assertCount(4, $positions);
        $this->assertSame(3, $withOnePosition);
        $this->assertSame($withOnePosition, $withFourPositions);
    }

    public function testNonInvestmentAccountHasNoPositions(): void
    {
        $checking = new Account($this->user, AccountType::Asset, 'Checking');
        $checking->setAssetKind(AssetAccountKind::Checking);
        $this->entityManager->persist($checking);
        $this->entityManager->flush();

        $this->assertSame([], $this->service->forAccount($checking, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31')));
    }

    /**
     * Totals behind an investment account's balance in the accounts list: a
     * priced and an unpriced position on one account, nothing on another, and
     * a checking account that gets no investment totals at all.
     */
    public function testInvestedSummariesForSeveralAccountsAtOnce(): void
    {
        $priced = $this->instrument('Priced');
        $this->trade($priced, InvestmentTransactionType::Buy, '2', '100.0000', '2026-09-01 10:00');
        $this->prices($priced, ['2026-09-29' => '115.0000']);
        $unpriced = $this->instrument('Unpriced');
        $this->trade($unpriced, InvestmentTransactionType::Buy, '1', '50.0000', '2026-09-01 10:00');

        $emptyBroker = $this->account('Empty broker', AssetAccountKind::Investment);
        $checking = $this->account('Checking', AssetAccountKind::Checking);

        $summaries = $this->service->investedSummaries([$this->broker, $emptyBroker, $checking]);

        // The priced position at its price (2 x 115), the unpriced one at what was paid (50).
        // Tax: 26% of the priced position's gain (230 - 200 = 30); the other has none.
        $this->assertSame(
            ['investedValue' => '280.0000', 'costBasis' => '250.0000', 'gainAmount' => '30.0000', 'gainPercent' => '12.0000', 'estimatedTax' => '7.8000'],
            $summaries[$this->broker->getId()->toRfc4122()]
        );
        $this->assertSame(
            ['investedValue' => '0.0000', 'costBasis' => '0.0000', 'gainAmount' => '0.0000', 'gainPercent' => '0.0000', 'estimatedTax' => '0.0000'],
            $summaries[$emptyBroker->getId()->toRfc4122()]
        );
        $this->assertArrayNotHasKey($checking->getId()->toRfc4122(), $summaries);
    }

    /** Two queries (trades, latest prices) however many investment accounts are listed. */
    public function testInvestedSummariesQueryCountDoesNotDependOnTheNumberOfAccounts(): void
    {
        $instrument = $this->instrument('ETF');
        $this->trade($instrument, InvestmentTransactionType::Buy, '1', '10.0000', '2026-09-01 10:00');
        $this->prices($instrument, ['2026-09-01' => '10.0000']);

        $withOneAccount = $this->countQueries(fn () => $this->service->investedSummaries([$this->broker]));

        $accounts = [$this->broker];
        foreach (['Second', 'Third'] as $name) {
            $accounts[] = $account = $this->account($name, AssetAccountKind::Investment);
            $this->trade($instrument, InvestmentTransactionType::Buy, '1', '10.0000', '2026-09-01 10:00', $account);
        }

        $summaries = null;
        $withThreeAccounts = $this->countQueries(function () use (&$summaries, $accounts) {
            $summaries = $this->service->investedSummaries($accounts);
        });

        $this->assertCount(3, $summaries);
        $this->assertSame(2, $withOneAccount);
        $this->assertSame($withOneAccount, $withThreeAccounts);
    }

    private function account(string $name, AssetAccountKind $kind): Account
    {
        $account = new Account($this->user, AccountType::Asset, $name);
        $account->setAssetKind($kind);
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

    private function trade(InvestmentInstrument $instrument, InvestmentTransactionType $type, string $quantity, string $unitPrice, string $occurredAt, ?Account $account = null): void
    {
        $this->entityManager->persist(new InvestmentTransaction(
            user: $this->user,
            account: $account ?? $this->broker,
            instrument: $instrument,
            type: $type,
            quantity: $quantity,
            unitPrice: $unitPrice,
            occurredAt: new \DateTimeImmutable($occurredAt),
        ));
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

    private function onlyPosition(string $from, string $to): OpenPositionResponse
    {
        $positions = $this->service->forAccount($this->broker, new \DateTimeImmutable($from), new \DateTimeImmutable($to));
        $this->assertCount(1, $positions);

        return $positions[0];
    }

    /**
     * @return array<string, array{0: string, 1: string}> date => [value, costBasis]
     */
    private function history(OpenPositionResponse $position): array
    {
        $byDate = [];
        foreach ($position->history as $point) {
            $byDate[$point->date] = [$point->value, $point->costBasis];
        }

        return $byDate;
    }

    private function countQueries(callable $callback): int
    {
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();
        $callback();

        return \count($debugData->getData()['default'] ?? []);
    }
}
