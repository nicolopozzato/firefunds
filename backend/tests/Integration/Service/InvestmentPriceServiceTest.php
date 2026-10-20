<?php

namespace App\Tests\Integration\Service;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentTransaction;
use App\Entity\InvestmentTransactionType;
use App\Entity\InvestmentInstrumentType;
use App\Entity\User;
use App\Investment\PriceProvider\LiveQuote;
use App\Investment\PriceProvider\MarketState;
use App\Investment\PriceProvider\PriceProviderInterface;
use App\Investment\Service\InvestmentPriceService;
use App\Investment\Service\PriceCalendar;
use App\Repository\InvestmentInstrumentRepository;
use App\Repository\InvestmentPriceRepository;
use App\Repository\InvestmentTransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class InvestmentPriceServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private InvestmentPriceRepository $priceRepository;
    private InvestmentInstrument $instrument;

    /** @var array<string, string> what the fake provider currently returns, day => close */
    private array $marketPrices = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->priceRepository = $container->get(InvestmentPriceRepository::class);

        $user = new User();
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setEmail(\sprintf('price-test-%s@example.test', uniqid()));
        $user->setPassword('hashed');
        $this->entityManager->persist($user);

        $this->instrument = new InvestmentInstrument($user, 'Test ETF', InvestmentInstrumentType::Etf);
        $this->instrument->setYahooTicker(\sprintf('TEST-%s', uniqid()));
        $this->entityManager->persist($this->instrument);

        $this->entityManager->flush();
    }

    public function testForwardFilledDayIsCorrectedOnceTheRealCloseArrives(): void
    {
        // Friday's close is known, Monday's bar has no close yet (provider lag).
        $this->marketPrices = ['2026-09-24' => '168.9200', '2026-09-25' => '169.7700'];
        $this->importThrough('2026-09-28');

        $this->assertPrice('2026-09-28', '169.7700');

        // Monday's real close shows up on the next fetch.
        $this->marketPrices['2026-09-28'] = '169.1400';
        $this->importThrough('2026-09-28');

        $this->assertPrice('2026-09-25', '169.7700');
        $this->assertPrice('2026-09-28', '169.1400');

        // Updated in place: still one row per day (Thu 24 .. Mon 28).
        $this->assertCount(5, $this->priceRepository->findByInstrumentInRange(
            $this->instrument,
            new \DateTimeImmutable('2026-09-24'),
            new \DateTimeImmutable('2026-09-28'),
        ));
    }

    public function testRecentDaysAreRecheckedEvenWithNoGapLeft(): void
    {
        $yesterday = $this->calendar()->yesterday();
        $day = static fn (int $daysBeforeYesterday) => $yesterday->modify(\sprintf('-%d days', $daysBeforeYesterday))->format('Y-m-d');

        $this->marketPrices = [$day(3) => '100.0000', $day(2) => '101.0000'];
        $this->seed();
        $this->assertPrice($day(1), '101.0000');
        $this->assertPrice($day(0), '101.0000');

        // History is already complete through yesterday, yet the late close must still land.
        $this->marketPrices[$day(1)] = '102.0000';
        $this->seed();

        $this->assertPrice($day(1), '102.0000');
        $this->assertPrice($day(0), '102.0000');
    }

    public function testUserEditedPriceIsNeverOverwritten(): void
    {
        $this->marketPrices = ['2026-09-24' => '168.9200', '2026-09-25' => '169.7700'];
        $this->importThrough('2026-09-28');

        $monday = $this->priceRepository->findOneByInstrumentAndDate($this->instrument, new \DateTimeImmutable('2026-09-28'));
        $monday->setManualPrice('150.0000');
        $this->entityManager->flush();

        $this->marketPrices['2026-09-28'] = '169.1400';
        $this->importThrough('2026-09-28');

        $this->assertPrice('2026-09-28', '150.0000');
        // The provider's newer close is kept, for when the user restores it.
        $monday->restoreProviderPrice();
        $this->assertSame(['169.1400', 'market'], [$monday->getPrice(), $monday->getSource()->value]);
    }

    /** Days without a close (the weekend) are carried over, and say so. */
    public function testCarriedOverDaysAreMarkedAsSuch(): void
    {
        $this->marketPrices = ['2026-09-25' => '169.7700', '2026-09-28' => '170.0000'];
        $this->importThrough('2026-09-28');

        $sources = [];
        foreach (['2026-09-25', '2026-09-26', '2026-09-27', '2026-09-28'] as $day) {
            $sources[$day] = $this->priceRepository->findOneByInstrumentAndDate($this->instrument, new \DateTimeImmutable($day))->getSource()->value;
        }

        $this->assertSame(['2026-09-25' => 'market', '2026-09-26' => 'filled', '2026-09-27' => 'filled', '2026-09-28' => 'market'], $sources);
    }

    /**
     * A trade dated months ago on an instrument with no prices: the history
     * must start on the trade's day, not a couple of weeks before yesterday.
     */
    public function testEnsureCompleteBackfillsFromTheFirstTrade(): void
    {
        $this->marketPrices = ['2026-06-08' => '140.0000', '2026-06-10' => '141.0000', '2026-09-29' => '170.0000'];
        $this->trade('2026-06-10 09:00:00');

        $this->assertFalse($this->service()->hasCompletePriceHistoryThroughYesterday($this->instrument));
        $this->assertTrue($this->service()->ensureCompletePriceHistoryThroughYesterday($this->instrument));

        $this->assertPrice('2026-06-10', '141.0000');
        $this->assertPrice('2026-07-15', '141.0000'); // forward-filled
        $this->assertPrice('2026-09-29', '170.0000');
        $this->assertSame('2026-06-10', $this->priceRepository->findEarliestForInstrument($this->instrument)?->getDate()->format('Y-m-d'));
    }

    public function testRecentPricesAloneAreNotCompleteWhenTradedEarlier(): void
    {
        $this->marketPrices = ['2026-09-28' => '169.0000', '2026-09-29' => '170.0000'];
        $this->importThrough('2026-09-29');
        $this->trade('2026-06-10 09:00:00');

        $this->assertFalse($this->service()->hasCompletePriceHistoryThroughYesterday($this->instrument));
    }

    private function trade(string $occurredAt): void
    {
        $account = new Account($this->instrument->getUser(), AccountType::Asset, 'Broker');
        $account->setAssetKind(AssetAccountKind::Investment);
        $this->entityManager->persist($account);
        $this->entityManager->persist(new InvestmentTransaction(
            user: $this->instrument->getUser(),
            account: $account,
            instrument: $this->instrument,
            type: InvestmentTransactionType::Buy,
            quantity: '1',
            unitPrice: '141.0000',
            occurredAt: new \DateTimeImmutable($occurredAt, new \DateTimeZone('UTC')),
        ));
        $this->entityManager->flush();
    }

    private function importThrough(string $through): void
    {
        $this->service()->importRange($this->instrument, new \DateTimeImmutable('2026-09-24'), new \DateTimeImmutable($through));
    }

    /**
     * A price the database refuses (here one too large for the column) used to
     * close the EntityManager, and every symbol after it failed too.
     */
    public function testOneSymbolFailingLeavesTheOthersUpdated(): void
    {
        $other = new InvestmentInstrument($this->instrument->getUser(), 'Other ETF', InvestmentInstrumentType::Etf);
        $other->setYahooTicker(\sprintf('OK-%s', uniqid()));
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        $broken = $this->instrument->getQuoteSymbol();
        $provider = new class($broken) implements PriceProviderInterface {
            public function __construct(private readonly string $broken)
            {
            }

            public function getClosingPrices(InvestmentInstrument $instrument, \DateTimeImmutable $from, \DateTimeImmutable $to): array
            {
                return ['2026-09-29' => $instrument->getQuoteSymbol() === $this->broken ? '100000000000000000000' : '42.0000'];
            }

            public function getLiveQuote(InvestmentInstrument $instrument): LiveQuote
            {
                return new LiveQuote(null, MarketState::Unknown);
            }
        };
        $container = self::getContainer();
        $service = new InvestmentPriceService(
            $container->get(InvestmentInstrumentRepository::class),
            $this->priceRepository,
            $container->get(InvestmentTransactionRepository::class),
            $provider,
            $this->entityManager,
            new NullLogger(),
            $this->calendar(),
            $container->get('doctrine'),
        );

        $service->updateAllInstruments();

        $price = $this->priceRepository->findOneByInstrumentAndDate(
            $this->entityManager->find(InvestmentInstrument::class, $other->getId()),
            new \DateTimeImmutable('2026-09-29'),
        );
        self::assertNotNull($price);
        self::assertSame('42.0000', $price->getPrice());
    }

    private function seed(): void
    {
        $this->service()->seedInitialPrice($this->instrument);
    }

    private function service(): InvestmentPriceService
    {
        $provider = new class($this->marketPrices) implements PriceProviderInterface {
            /** @param array<string, string> $prices */
            public function __construct(private readonly array $prices)
            {
            }

            public function getClosingPrices(InvestmentInstrument $instrument, \DateTimeImmutable $from, \DateTimeImmutable $to): array
            {
                return array_filter(
                    $this->prices,
                    static fn (string $day) => $day >= $from->format('Y-m-d') && $day <= $to->format('Y-m-d'),
                    \ARRAY_FILTER_USE_KEY
                );
            }

            public function getLiveQuote(InvestmentInstrument $instrument): LiveQuote
            {
                return new LiveQuote(null, MarketState::Unknown);
            }
        };

        return new InvestmentPriceService(
            self::getContainer()->get(InvestmentInstrumentRepository::class),
            $this->priceRepository,
            self::getContainer()->get(InvestmentTransactionRepository::class),
            $provider,
            $this->entityManager,
            new NullLogger(),
            $this->calendar(),
            self::getContainer()->get('doctrine'),
        );
    }

    /** A fixed "now", so "yesterday" never depends on when the suite runs. */
    private function calendar(): PriceCalendar
    {
        return new PriceCalendar(new MockClock('2026-09-30 10:00:00', 'UTC'), 'Europe/Rome');
    }

    private function assertPrice(string $day, string $expected): void
    {
        $price = $this->priceRepository->findOneByInstrumentAndDate($this->instrument, new \DateTimeImmutable($day));

        self::assertNotNull($price, \sprintf('No price recorded for %s', $day));
        self::assertSame($expected, $price->getPrice());
    }
}
