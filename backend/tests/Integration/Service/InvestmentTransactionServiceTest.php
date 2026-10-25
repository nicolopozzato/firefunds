<?php

namespace App\Tests\Integration\Service;

use App\Account\Service\AssetValueHistory;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\Currency;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentInstrumentType;
use App\Entity\User;
use App\Investment\DTO\CreateInvestmentTransactionRequest;
use App\Investment\Service\InvestmentTransactionService;
use App\Investment\Service\OpenPositionService;
use App\Repository\AccountRepository;
use App\Repository\InvestmentTransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class InvestmentTransactionServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private InvestmentTransactionService $service;
    private InvestmentTransactionRepository $investmentTransactionRepository;
    private AccountRepository $accountRepository;
    private User $user;
    private Account $brokerAccount;
    private InvestmentInstrument $instrument;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->service = $container->get(InvestmentTransactionService::class);
        $this->investmentTransactionRepository = $container->get(InvestmentTransactionRepository::class);
        $this->accountRepository = $container->get(AccountRepository::class);

        $this->user = new User();
        $this->user->setFirstName('Test');
        $this->user->setLastName('User');
        $this->user->setEmail(\sprintf('investment-test-%s@example.test', uniqid()));
        $this->user->setPassword('hashed');
        $this->entityManager->persist($this->user);

        $currency = $this->entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']);
        if ($currency === null) {
            $currency = new Currency('EUR', 'Euro', '€', 2);
            $this->entityManager->persist($currency);
        }

        $this->brokerAccount = new Account($this->user, AccountType::Asset, 'Broker');
        $this->brokerAccount->setAssetKind(AssetAccountKind::Investment);
        $this->brokerAccount->setCurrency($currency);
        $this->brokerAccount->setOpeningBalance('10000.0000');
        $this->entityManager->persist($this->brokerAccount);

        $this->instrument = new InvestmentInstrument($this->user, 'Test ETF', InvestmentInstrumentType::Etf);
        $this->entityManager->persist($this->instrument);

        $this->entityManager->flush();
    }

    public function testBuyReducesCashBalance(): void
    {
        $request = new CreateInvestmentTransactionRequest(
            description: null,
            accountId: (string) $this->brokerAccount->getId(),
            instrumentId: (string) $this->instrument->getId(),
            type: 'buy',
            quantity: '10',
            unitPrice: '100.0000',
            fees: '5.0000',
            occurredAt: '2026-01-01T00:00:00+00:00',
        );

        $this->service->create($this->user, $request);
        $this->entityManager->flush();

        $balances = $this->accountRepository->getCurrentBalances([$this->brokerAccount]);
        $balance = $balances[(string) $this->brokerAccount->getId()];

        // 10000 opening - (10 * 100 + 5 fees) = 8995
        $this->assertSame('8995.0000', $balance);
    }

    /**
     * Like an overdrawn checking account, selling more than is held isn't
     * blocked: keeping the records consistent is up to the user.
     */
    public function testSellIncreasesCashBalanceEvenBeyondHoldings(): void
    {
        $this->service->create($this->user, new CreateInvestmentTransactionRequest(
            description: null,
            accountId: (string) $this->brokerAccount->getId(),
            instrumentId: (string) $this->instrument->getId(),
            type: 'buy',
            quantity: '10',
            unitPrice: '100.0000',
            fees: '0',
            occurredAt: '2026-01-01T00:00:00+00:00',
        ));
        $this->service->create($this->user, new CreateInvestmentTransactionRequest(
            description: null,
            accountId: (string) $this->brokerAccount->getId(),
            instrumentId: (string) $this->instrument->getId(),
            type: 'sell',
            quantity: '11',
            unitPrice: '110.0000',
            fees: '0',
            occurredAt: '2026-01-02T00:00:00+00:00',
        ));
        $this->entityManager->flush();

        $balances = $this->accountRepository->getCurrentBalances([$this->brokerAccount]);

        // 10000 opening - 10 * 100 + 11 * 110 = 10210
        $this->assertSame('10210.0000', $balances[(string) $this->brokerAccount->getId()]);
    }

    /**
     * Capital gains tax withheld by the broker on a SELL comes out of the
     * proceeds just like fees, so the cash balance matches the broker's.
     */
    public function testSellFeesAndTaxesReduceProceeds(): void
    {
        $this->service->create($this->user, new CreateInvestmentTransactionRequest(
            description: null,
            accountId: (string) $this->brokerAccount->getId(),
            instrumentId: (string) $this->instrument->getId(),
            type: 'buy',
            quantity: '10',
            unitPrice: '100.0000',
            fees: '0',
            occurredAt: '2026-01-01T10:00:00+00:00',
        ));
        $this->service->create($this->user, new CreateInvestmentTransactionRequest(
            description: null,
            accountId: (string) $this->brokerAccount->getId(),
            instrumentId: (string) $this->instrument->getId(),
            type: 'sell',
            quantity: '10',
            unitPrice: '120.0000',
            fees: '5.0000',
            taxes: '52.0000',
            occurredAt: '2026-01-02T10:00:00+00:00',
        ));
        $this->entityManager->flush();

        // 10000 opening - 10 * 100 + (10 * 120 - 5 fees - 52 taxes) = 10143
        $balances = $this->accountRepository->getCurrentBalances([$this->brokerAccount]);
        $this->assertSame('10143.0000', $balances[(string) $this->brokerAccount->getId()]);

        $history = $this->accountRepository->getDailyBalanceHistory(
            $this->brokerAccount,
            new \DateTimeImmutable('2026-01-02'),
            new \DateTimeImmutable('2026-01-02'),
        );
        $this->assertSame('10143.0000', $history[0]['balance']);
    }

    /**
     * Taxes on a BUY (e.g. Tobin tax) are part of what the holding cost, like
     * fees; taxes on a SELL reduce its proceeds but leave the average cost of
     * what's still held untouched.
     */
    public function testBuyTaxesAddToCostBasisAndSellTaxesDoNot(): void
    {
        $this->service->create($this->user, new CreateInvestmentTransactionRequest(
            description: null,
            accountId: (string) $this->brokerAccount->getId(),
            instrumentId: (string) $this->instrument->getId(),
            type: 'buy',
            quantity: '10',
            unitPrice: '100.0000',
            fees: '1.0000',
            taxes: '2.0000',
            occurredAt: '2026-01-01T10:00:00+00:00',
        ));
        $this->service->create($this->user, new CreateInvestmentTransactionRequest(
            description: null,
            accountId: (string) $this->brokerAccount->getId(),
            instrumentId: (string) $this->instrument->getId(),
            type: 'sell',
            quantity: '5',
            unitPrice: '150.0000',
            fees: '0',
            taxes: '64.0000',
            occurredAt: '2026-01-02T10:00:00+00:00',
        ));
        $this->entityManager->flush();

        $positions = $this->investmentTransactionRepository->getPositions($this->brokerAccount);

        // Cost 10 * 100 + 1 + 2 = 1003 (avg 100.30); selling 5 removes 5 * 100.30.
        $this->assertCount(1, $positions);
        $this->assertSame('5.000000', $positions[0]['quantity']);
        $this->assertSame('501.5000', $positions[0]['costBasis']);
        $this->assertSame('100.3000', $positions[0]['averageCost']);
    }

    public function testNegativeTaxesAreRejected(): void
    {
        $validator = self::getContainer()->get(ValidatorInterface::class);

        $violations = $validator->validate(new CreateInvestmentTransactionRequest(
            description: null,
            accountId: (string) $this->brokerAccount->getId(),
            instrumentId: (string) $this->instrument->getId(),
            type: 'sell',
            quantity: '1',
            unitPrice: '100.0000',
            taxes: '-1',
            occurredAt: '2026-01-01T10:00:00+00:00',
        ));

        $this->assertCount(1, $violations);
        $this->assertSame('taxes', $violations[0]->getPropertyPath());
    }

    public function testPositionsReflectAverageCostAcrossBuysAndSells(): void
    {
        // Buy 10 @ 100, buy 10 @ 120 -> avg cost 110, qty 20
        $this->service->create($this->user, new CreateInvestmentTransactionRequest(
            description: null,
            accountId: (string) $this->brokerAccount->getId(),
            instrumentId: (string) $this->instrument->getId(),
            type: 'buy',
            quantity: '10',
            unitPrice: '100.0000',
            fees: '0',
            occurredAt: '2026-01-01T00:00:00+00:00',
        ));
        $this->service->create($this->user, new CreateInvestmentTransactionRequest(
            description: null,
            accountId: (string) $this->brokerAccount->getId(),
            instrumentId: (string) $this->instrument->getId(),
            type: 'buy',
            quantity: '10',
            unitPrice: '120.0000',
            fees: '0',
            occurredAt: '2026-01-02T00:00:00+00:00',
        ));
        // Sell 5 -> average cost stays 110, remaining qty 15
        $this->service->create($this->user, new CreateInvestmentTransactionRequest(
            description: null,
            accountId: (string) $this->brokerAccount->getId(),
            instrumentId: (string) $this->instrument->getId(),
            type: 'sell',
            quantity: '5',
            unitPrice: '130.0000',
            fees: '0',
            occurredAt: '2026-01-03T00:00:00+00:00',
        ));
        $this->entityManager->flush();

        $positions = $this->investmentTransactionRepository->getPositions($this->brokerAccount);

        $this->assertCount(1, $positions);
        $this->assertSame('15.000000', $positions[0]['quantity']);
        $this->assertSame('1650.0000', $positions[0]['costBasis']);
        $this->assertSame('110.0000', $positions[0]['averageCost']);
        $this->assertSame('2026-01-01', $positions[0]['openedOn']);
    }

    public function testPositionOpenedOnRestartsAfterBeingFullySold(): void
    {
        foreach ([['buy', '2026-01-01'], ['sell', '2026-02-01'], ['buy', '2026-03-01']] as [$type, $day]) {
            $this->service->create($this->user, new CreateInvestmentTransactionRequest(
                description: null,
                accountId: (string) $this->brokerAccount->getId(),
                instrumentId: (string) $this->instrument->getId(),
                type: $type,
                quantity: '10',
                unitPrice: '100.0000',
                fees: '0',
                occurredAt: $day.'T10:00:00+00:00',
            ));
        }
        $this->entityManager->flush();

        $positions = $this->investmentTransactionRepository->getPositions($this->brokerAccount);

        $this->assertCount(1, $positions);
        $this->assertSame('2026-03-01', $positions[0]['openedOn']);
    }

    /**
     * occurred_at is a timestamp without time zone: a request carrying a non-UTC
     * offset (e.g. Europe/Rome's +02:00) must be normalized to the equivalent UTC
     * instant before being stored, exactly like TransactionService does for plain
     * money transactions. Otherwise the offset is silently dropped and the wrong
     * wall-clock value is persisted (e.g. 06:21+02:00 stored as literal "06:21"
     * instead of "04:21").
     */
    /** An instant (with an offset) is kept as the clock time in the user's timezone. */
    public function testAnInstantIsReadInTheUsersTimezone(): void
    {
        $saved = $this->service->create($this->user, new CreateInvestmentTransactionRequest(
            description: null,
            accountId: (string) $this->brokerAccount->getId(),
            instrumentId: (string) $this->instrument->getId(),
            type: 'buy',
            quantity: '1',
            unitPrice: '100.0000',
            fees: '0',
            occurredAt: '2026-09-29T06:21:00+02:00',
        ));
        $this->entityManager->flush();

        $this->assertSame('2026-09-29T06:21:00+02:00', $saved->transaction->getOccurredAt()->format(\DateTimeInterface::ATOM));
        $this->assertSame('Europe/Rome', $saved->transaction->getTimezone());
    }

    /** A time without an offset is kept as written, in the zone sent with it. */
    public function testAClockTimeIsKeptAsWrittenWithItsZone(): void
    {
        $saved = $this->service->create($this->user, new CreateInvestmentTransactionRequest(
            description: null,
            accountId: (string) $this->brokerAccount->getId(),
            instrumentId: (string) $this->instrument->getId(),
            type: 'buy',
            quantity: '1',
            unitPrice: '100.0000',
            fees: '0',
            occurredAt: '2026-09-29T21:15:00',
            timezone: 'America/New_York',
        ));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $stored = $this->entityManager->find(\App\Entity\InvestmentTransaction::class, $saved->transaction->getId());
        $this->assertSame('2026-09-29T21:15:00-04:00', $stored->getOccurredAt()->format(\DateTimeInterface::ATOM));
        $this->assertSame('America/New_York', $stored->getTimezone());
    }

    /**
     * Updating only the description (occurredAt resubmitted unchanged) must not
     * produce a spurious "occurredAt" audit log entry, nor move the time.
     */
    public function testUpdatingUnrelatedFieldDoesNotChangeOccurredAt(): void
    {
        $saved = $this->service->create($this->user, new CreateInvestmentTransactionRequest(
            description: 'Original',
            accountId: (string) $this->brokerAccount->getId(),
            instrumentId: (string) $this->instrument->getId(),
            type: 'buy',
            quantity: '1',
            unitPrice: '100.0000',
            fees: '0',
            occurredAt: '2026-09-29T06:21:00+02:00',
        ));
        $this->entityManager->flush();

        $updated = $this->service->update($saved->transaction, new CreateInvestmentTransactionRequest(
            description: 'Updated',
            accountId: (string) $this->brokerAccount->getId(),
            instrumentId: (string) $this->instrument->getId(),
            type: 'buy',
            quantity: '1',
            unitPrice: '100.0000',
            fees: '0',
            occurredAt: '2026-09-29T06:21:00+02:00',
        ));
        $this->entityManager->flush();

        $this->assertSame('2026-09-29T06:21:00+02:00', $updated->transaction->getOccurredAt()->format(\DateTimeInterface::ATOM));
    }

    /**
     * A BUY made at 00:21 in Rome on Sept 29 (22:21 UTC on Sept 28) shows up
     * as invested value on Sept 29, the day written where it happened.
     */
    public function testDailyInvestedValueHistoryAttributesPurchaseToTheLocalDay(): void
    {
        $this->entityManager->persist(new \App\Entity\InvestmentPrice(
            $this->instrument,
            new \DateTimeImmutable('2026-09-28'),
            '160.0000',
        ));
        $this->entityManager->flush();

        $this->service->create($this->user, new CreateInvestmentTransactionRequest(
            description: null,
            accountId: (string) $this->brokerAccount->getId(),
            instrumentId: (string) $this->instrument->getId(),
            type: 'buy',
            quantity: '0.5',
            unitPrice: '160.0000',
            fees: '0',
            occurredAt: '2026-09-29T00:21:00+02:00',
        ));
        $this->entityManager->flush();

        $values = self::getContainer()->get(AssetValueHistory::class)->forAccounts(
            [$this->brokerAccount],
            new \DateTimeImmutable('2026-09-28'),
            new \DateTimeImmutable('2026-09-29'),
        );
        $id = $this->brokerAccount->getId()->toRfc4122();

        $this->assertSame('0.0000', $values->investedOn($id, '2026-09-28'));
        $this->assertSame('80.0000', $values->investedOn($id, '2026-09-29'));
    }

    /**
     * Units bought (and partly sold) before the range still count on its
     * first day: a chart for "this year" must not start from zero.
     */
    public function testTheValueHeldIncludesHoldingsFromBeforeTheRange(): void
    {
        $this->entityManager->persist(new \App\Entity\InvestmentPrice($this->instrument, new \DateTimeImmutable('2025-06-01'), '100.0000'));
        $this->entityManager->persist(new \App\Entity\InvestmentPrice($this->instrument, new \DateTimeImmutable('2026-09-01'), '150.0000'));
        $this->service->create($this->user, $this->trade('buy', '3', '100.0000'));
        $this->service->create($this->user, $this->trade('sell', '1', '100.0000'));
        $this->entityManager->flush();

        $values = self::getContainer()->get(AssetValueHistory::class)->forAccounts(
            [$this->brokerAccount],
            new \DateTimeImmutable('2026-09-01'),
            new \DateTimeImmutable('2026-09-02'),
        );
        $id = $this->brokerAccount->getId()->toRfc4122();

        $this->assertSame('300.0000', $values->investedOn($id, '2026-09-01'));
        $this->assertSame('300.0000', $values->investedOn($id, '2026-09-02'));
    }

    /**
     * The test instrument has no ticker, so a backfill attempt can never reach
     * the market: there is nothing to fetch, it is valued at its trades' and
     * the user's own prices, so there is nothing to warn about either.
     */
    public function testAnInstrumentWithoutTickerIsNeverReportedIncomplete(): void
    {
        $saved = $this->service->create($this->user, new CreateInvestmentTransactionRequest(
            description: null,
            accountId: (string) $this->brokerAccount->getId(),
            instrumentId: (string) $this->instrument->getId(),
            type: 'buy',
            quantity: '1',
            unitPrice: '100.0000',
            fees: '0',
            occurredAt: '2026-01-01T00:00:00+00:00',
        ));
        $this->entityManager->flush();

        $this->assertFalse($saved->pricesIncomplete);
    }

    /**
     * When the instrument's price history already covers the trade's day
     * through yesterday, no backfill is needed and pricesIncomplete must be false.
     */
    public function testCreateReportsPricesCompleteWhenHistoryAlreadyCoversYesterday(): void
    {
        $this->entityManager->persist(new \App\Entity\InvestmentPrice($this->instrument, new \DateTimeImmutable('2026-01-01'), '100.0000'));
        // The service's own "yesterday" (APP_TIMEZONE), not the UTC server clock's:
        // between midnight and 2am in Rome the two are different days.
        $yesterday = self::getContainer()->get(\App\Investment\Service\PriceCalendar::class)->yesterday();
        $this->entityManager->persist(new \App\Entity\InvestmentPrice($this->instrument, $yesterday, '100.0000'));
        $this->entityManager->flush();

        $saved = $this->service->create($this->user, new CreateInvestmentTransactionRequest(
            description: null,
            accountId: (string) $this->brokerAccount->getId(),
            instrumentId: (string) $this->instrument->getId(),
            type: 'buy',
            quantity: '1',
            unitPrice: '100.0000',
            fees: '0',
            occurredAt: '2026-01-01T00:00:00+00:00',
        ));
        $this->entityManager->flush();

        $this->assertFalse($saved->pricesIncomplete);
    }

    /**
     * A typical open position: buy
     * 0.5 units at 160, price moves to 168 the next day (position value follows
     * the market with quantity unchanged), then buy another 0.5 units at 164
     * (quantity and cost basis both step up, average cost becomes 162).
     */
    public function testDailyPositionHistoryReflectsBothMarketMovesAndQuantityChanges(): void
    {
        $this->entityManager->persist(new \App\Entity\InvestmentPrice($this->instrument, new \DateTimeImmutable('2026-01-01'), '160.0000'));
        $this->entityManager->persist(new \App\Entity\InvestmentPrice($this->instrument, new \DateTimeImmutable('2026-01-02'), '168.0000'));
        $this->entityManager->persist(new \App\Entity\InvestmentPrice($this->instrument, new \DateTimeImmutable('2026-01-03'), '164.0000'));
        $this->entityManager->flush();

        $this->service->create($this->user, new CreateInvestmentTransactionRequest(
            description: null,
            accountId: (string) $this->brokerAccount->getId(),
            instrumentId: (string) $this->instrument->getId(),
            type: 'buy',
            quantity: '0.5',
            unitPrice: '160.0000',
            fees: '0',
            occurredAt: '2026-01-01T00:00:00+00:00',
        ));
        $this->service->create($this->user, new CreateInvestmentTransactionRequest(
            description: null,
            accountId: (string) $this->brokerAccount->getId(),
            instrumentId: (string) $this->instrument->getId(),
            type: 'buy',
            quantity: '0.5',
            unitPrice: '164.0000',
            fees: '0',
            occurredAt: '2026-01-03T00:00:00+00:00',
        ));
        $this->entityManager->flush();

        [$position] = self::getContainer()->get(OpenPositionService::class)->forAccount(
            $this->brokerAccount,
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-03'),
        );

        $byDate = [];
        foreach ($position->history as $point) {
            $byDate[$point->date] = ['value' => $point->value, 'costBasis' => $point->costBasis];
        }

        $this->assertSame('80.0000', $byDate['2026-01-01']['value']);
        $this->assertSame('80.0000', $byDate['2026-01-01']['costBasis']);

        // Price moved to 168 but quantity is unchanged: value follows the market, cost basis doesn't.
        $this->assertSame('84.0000', $byDate['2026-01-02']['value']);
        $this->assertSame('80.0000', $byDate['2026-01-02']['costBasis']);

        // Bought another 0.5 @ 164: quantity is now 1.0, cost basis steps up to 162 (average cost).
        $this->assertSame('164.0000', $byDate['2026-01-03']['value']);
        $this->assertSame('162.0000', $byDate['2026-01-03']['costBasis']);
    }

    public function testGetOpenPositionsWithMarketDataComputesGainAgainstCostBasis(): void
    {
        $this->entityManager->persist(new \App\Entity\InvestmentPrice($this->instrument, new \DateTimeImmutable('2026-01-01'), '160.0000'));
        $this->entityManager->flush();

        $this->service->create($this->user, new CreateInvestmentTransactionRequest(
            description: null,
            accountId: (string) $this->brokerAccount->getId(),
            instrumentId: (string) $this->instrument->getId(),
            type: 'buy',
            quantity: '1',
            unitPrice: '162.0000',
            fees: '0',
            occurredAt: '2026-01-01T00:00:00+00:00',
        ));
        $this->entityManager->flush();

        $positions = self::getContainer()->get(OpenPositionService::class)->forAccount(
            $this->brokerAccount,
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-01'),
        );

        $this->assertCount(1, $positions);
        $this->assertSame('160.0000', $positions[0]->currentPrice);
        $this->assertSame('160.0000', $positions[0]->currentValue);
        $this->assertSame('-2.0000', $positions[0]->gainAmount);
        $this->assertSame('-1.2345', $positions[0]->gainPercent);
    }

    public function testAmountDefaultsToQuantityTimesUnitPrice(): void
    {
        $saved = $this->service->create($this->user, $this->trade('buy', '0.12', '100.0000'));

        $this->assertSame('12.0000', $saved->transaction->getAmount());
        $this->assertSame('9988.0000', $this->cashBalance());
    }

    /**
     * A 50.00 monthly savings-plan buy of 0.300987 units quoted at 166.12: the broker charged
     * 50.00, not 49.99996. Cash, cost basis and gain all follow the amount;
     * the unit price stays as quoted.
     */
    public function testBrokerAmountDrivesCashCostBasisAndGain(): void
    {
        $this->entityManager->persist(new \App\Entity\InvestmentPrice($this->instrument, new \DateTimeImmutable('2026-01-01'), '170.0000'));
        $saved = $this->service->create($this->user, $this->trade('buy', '0.300987', '166.1200', amount: '50.00'));
        $this->entityManager->flush();

        $this->assertSame('166.1200', $saved->transaction->getUnitPrice());
        $this->assertSame('50.0000', $saved->transaction->getAmount());
        $this->assertSame('9950.0000', $this->cashBalance());

        [$position] = self::getContainer()->get(OpenPositionService::class)->forAccount(
            $this->brokerAccount,
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-01'),
        );
        $this->assertSame('50.0000', $position->costBasis);
        $this->assertSame('166.1201', $position->averageCost);
        $this->assertSame('51.1677', $position->currentValue); // 0.300987 × 170 = 51.16779
        $this->assertSame('1.1677', $position->gainAmount);
    }

    public function testSellProceedsFollowTheAmount(): void
    {
        $this->service->create($this->user, $this->trade('buy', '0.300987', '166.1200', amount: '50.00'));
        $this->service->create($this->user, $this->trade('sell', '0.300987', '169.4400', amount: '51.00'));
        $this->entityManager->flush();

        $this->assertSame('10001.0000', $this->cashBalance());
    }

    public function testAmountFarFromQuantityTimesUnitPriceIsKeptAsEntered(): void
    {
        // Fees, rounding and another currency make the broker's figure its own: never checked.
        $investment = $this->service->create($this->user, $this->trade('buy', '0.12', '100.0000', amount: '120.00'));

        $this->assertSame('120.0000', $investment->transaction->getAmount());
    }

    public function testUpdateChangesTheAmount(): void
    {
        $saved = $this->service->create($this->user, $this->trade('buy', '0.300987', '166.1200'));
        $this->service->update($saved->transaction, $this->trade('buy', '0.300987', '166.1200', amount: '50.00'));
        $this->entityManager->flush();

        $this->assertSame('50.0000', $saved->transaction->getAmount());
        $this->assertSame('9950.0000', $this->cashBalance());
    }

    /**
     * Tax if sold today, position by position: the user's 26% on one ETF, the
     * instrument's own 15.46% on another, nothing on a position at a loss
     * (which doesn't offset the others).
     */
    public function testEstimatedTaxUsesEachInstrumentsRateAndIgnoresLosses(): void
    {
        $whiteList = new InvestmentInstrument($this->user, 'Overnight ETF', InvestmentInstrumentType::Etf);
        $whiteList->setCapitalGainsTaxRate('15.4600');
        $losing = new InvestmentInstrument($this->user, 'Losing ETF', InvestmentInstrumentType::Etf);
        $this->entityManager->persist($whiteList);
        $this->entityManager->persist($losing);
        foreach ([[$this->instrument, '150.0000'], [$whiteList, '200.0000'], [$losing, '50.0000']] as [$instrument, $price]) {
            $this->entityManager->persist(new \App\Entity\InvestmentPrice($instrument, new \DateTimeImmutable('2026-01-01'), $price));
        }
        $this->entityManager->flush();

        foreach ([$this->instrument, $whiteList, $losing] as $instrument) {
            $this->service->create($this->user, new CreateInvestmentTransactionRequest(
                description: null,
                accountId: (string) $this->brokerAccount->getId(),
                instrumentId: (string) $instrument->getId(),
                type: 'buy',
                quantity: '1',
                unitPrice: '100.0000',
                occurredAt: '2026-01-01T00:00:00+00:00',
            ));
        }
        $this->entityManager->flush();

        $openPositions = self::getContainer()->get(OpenPositionService::class);
        $byName = [];
        foreach ($openPositions->forAccount($this->brokerAccount, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-01')) as $position) {
            $byName[$position->instrumentName] = $position;
        }

        $this->assertSame('26.0000', $byName['Test ETF']->taxRate);
        $this->assertSame('13.0000', $byName['Test ETF']->estimatedTax); // 26% of 50
        $this->assertSame('15.4600', $byName['Overnight ETF']->taxRate);
        $this->assertSame('15.4600', $byName['Overnight ETF']->estimatedTax); // 15.46% of 100
        $this->assertSame('0.0000', $byName['Losing ETF']->estimatedTax);

        $summary = $openPositions->investedSummaries([$this->brokerAccount])[(string) $this->brokerAccount->getId()];
        $this->assertSame('100.0000', $summary['gainAmount']); // 50 + 100 - 50
        $this->assertSame('28.4600', $summary['estimatedTax'], 'the loss does not reduce the tax on the gains');
    }

    public function testUserRateIsUsedWhenTheInstrumentHasNone(): void
    {
        $this->user->setCapitalGainsTaxRate('12.5000');
        $this->entityManager->persist(new \App\Entity\InvestmentPrice($this->instrument, new \DateTimeImmutable('2026-01-01'), '200.0000'));
        $this->service->create($this->user, $this->trade('buy', '1', '100.0000'));
        $this->entityManager->flush();

        $summary = self::getContainer()->get(OpenPositionService::class)->investedSummaries([$this->brokerAccount])[(string) $this->brokerAccount->getId()];

        $this->assertSame('12.5000', $summary['estimatedTax']);
    }

    private function trade(string $type, string $quantity, string $unitPrice, ?string $amount = null): CreateInvestmentTransactionRequest
    {
        return new CreateInvestmentTransactionRequest(
            description: null,
            accountId: (string) $this->brokerAccount->getId(),
            instrumentId: (string) $this->instrument->getId(),
            type: $type,
            quantity: $quantity,
            unitPrice: $unitPrice,
            occurredAt: '2026-01-01T00:00:00+00:00',
            amount: $amount,
        );
    }

    private function cashBalance(): string
    {
        return $this->accountRepository->getCurrentBalances([$this->brokerAccount])[(string) $this->brokerAccount->getId()];
    }
}
