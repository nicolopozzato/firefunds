<?php

namespace App\Tests\Integration\Investment;

use App\Account\Service\AssetValueHistory;
use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\Currency;
use App\Entity\ExchangeRateSource;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentInstrumentType;
use App\Entity\InvestmentPrice;
use App\Entity\InvestmentTransaction;
use App\Entity\InvestmentTransactionType;
use App\Entity\User;
use App\Investment\Service\OpenPositionService;
use App\Investment\Service\PortfolioService;
use App\Repository\ExchangeRateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An instrument quoted in dollars on a broker account in euros: its price is
 * converted at each day's rate wherever the holding is valued (the account's
 * positions and totals, the portfolio, the value history). 1 EUR = 1.25 USD
 * here, so 100 USD is 80 EUR.
 */
class HoldingConversionTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private User $user;
    private Account $broker;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->user = new User();
        $this->user->setFirstName('Conversion');
        $this->user->setLastName('Test');
        $this->user->setEmail(\sprintf('conversion-%s@example.test', uniqid()));
        $this->user->setPassword('hashed');
        $this->entityManager->persist($this->user);

        $this->broker = new Account($this->user, AccountType::Asset, 'Broker');
        $this->broker->setAssetKind(AssetAccountKind::Investment);
        $this->broker->setCurrency($this->entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']));
        $this->entityManager->persist($this->broker);
        $this->entityManager->flush();

        $container->get(ExchangeRateRepository::class)->upsert('USD', [
            '2026-10-01' => ['1.25000000', ExchangeRateSource::Ecb],
            '2026-10-02' => ['1.25000000', ExchangeRateSource::Ecb],
            '2026-10-03' => ['1.25000000', ExchangeRateSource::Ecb],
        ]);
    }

    public function testThePositionIsValuedInTheAccountsCurrencyAndShowsTheQuote(): void
    {
        $this->holdTwoDollarUnits('USD');

        [$position] = self::getContainer()->get(OpenPositionService::class)->forAccount($this->broker, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-03'));

        $this->assertSame('100.0000', $position->quotePrice);
        $this->assertSame('USD', $position->quoteCurrency);
        $this->assertSame('80.0000', $position->currentPrice, '100 USD in euros');
        $this->assertSame('160.0000', $position->currentValue);
        $this->assertSame('0.0000', $position->gainAmount, 'paid 160 EUR for it');
        $this->assertSame(['160.0000', '160.0000', '160.0000'], array_map(static fn ($point) => $point->value, $position->history));
    }

    public function testTheAccountsTotalsTheValueHistoryAndThePortfolioAgree(): void
    {
        $this->holdTwoDollarUnits('USD');
        $container = self::getContainer();

        $summary = $container->get(OpenPositionService::class)->investedSummaries([$this->broker], new \DateTimeImmutable('2026-10-02'));
        $this->assertSame('160.0000', $summary[$this->broker->getId()->toRfc4122()]['investedValue']);

        $values = $container->get(AssetValueHistory::class)->forAccounts([$this->broker], new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-03'));
        $this->assertSame('160.0000', $values->investedOn($this->broker->getId()->toRfc4122(), '2026-10-02'));

        $portfolio = $container->get(PortfolioService::class)->forUser($this->user, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-03'));
        $this->assertSame('160.0000', $portfolio->marketValue);
    }

    public function testAnInstrumentInTheAccountsOwnCurrencyIsNotTouched(): void
    {
        $this->holdTwoDollarUnits('EUR');

        [$position] = self::getContainer()->get(OpenPositionService::class)->forAccount($this->broker, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-03'));

        $this->assertSame('100.0000', $position->currentPrice);
        $this->assertSame('200.0000', $position->currentValue);
    }

    public function testWithoutARateTheValueIsUnknownRatherThanAnotherCurrencysNumber(): void
    {
        $this->holdTwoDollarUnits('JPY');

        [$position] = self::getContainer()->get(OpenPositionService::class)->forAccount($this->broker, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-03'));

        $this->assertNull($position->currentPrice);
        $this->assertNull($position->currentValue);
        $this->assertSame('100.0000', $position->quotePrice);
    }

    public function testThePortfolioIsInTheUsersCurrencyWhateverTheAccountsAre(): void
    {
        $dollarBroker = new Account($this->user, AccountType::Asset, 'Broker USA');
        $dollarBroker->setAssetKind(AssetAccountKind::Investment);
        $dollarBroker->setCurrency($this->entityManager->getRepository(Currency::class)->findOneBy(['code' => 'USD']));
        $this->entityManager->persist($dollarBroker);
        $instrument = new InvestmentInstrument($this->user, 'S&P 500', InvestmentInstrumentType::Etf);
        $instrument->setCurrency('USD');
        $this->entityManager->persist($instrument);
        foreach (['2026-09-20', '2026-10-01', '2026-10-02', '2026-10-03'] as $day) {
            $this->entityManager->persist(new InvestmentPrice($instrument, new \DateTimeImmutable($day), '100.0000'));
        }
        $trade = new InvestmentTransaction(
            user: $this->user,
            account: $dollarBroker,
            instrument: $instrument,
            type: InvestmentTransactionType::Buy,
            quantity: '2',
            unitPrice: '100.0000',
            occurredAt: new \DateTimeImmutable('2026-09-20 10:00', new \DateTimeZone('UTC')),
        );
        $trade->setAmount('200.0000');
        $this->entityManager->persist($trade);
        $this->entityManager->flush();

        $portfolio = self::getContainer()->get(PortfolioService::class)->forUser($this->user, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-03'));

        $this->assertSame('€', $portfolio->currencySymbol);
        $this->assertSame('160.0000', $portfolio->marketValue, '2 x 100 USD at 1 EUR = 1.25 USD');
        $this->assertSame('160.0000', $portfolio->costBasis, 'the 200 USD it cost, at the rate of the day it was bought');
        $this->assertSame('0.0000', $portfolio->gainAmount);
    }

    /** Two units quoted at 100 in the currency, bought for 160 euros. */
    private function holdTwoDollarUnits(string $quoteCurrency): void
    {
        $instrument = new InvestmentInstrument($this->user, 'S&P 500', InvestmentInstrumentType::Etf);
        $instrument->setCurrency($quoteCurrency);
        $this->entityManager->persist($instrument);
        foreach (['2026-09-20', '2026-10-01', '2026-10-02', '2026-10-03'] as $day) {
            $this->entityManager->persist(new InvestmentPrice($instrument, new \DateTimeImmutable($day), '100.0000'));
        }
        $trade = new InvestmentTransaction(
            user: $this->user,
            account: $this->broker,
            instrument: $instrument,
            type: InvestmentTransactionType::Buy,
            quantity: '2',
            unitPrice: '100.0000',
            occurredAt: new \DateTimeImmutable('2026-09-20 10:00', new \DateTimeZone('UTC')),
        );
        $trade->setAmount($quoteCurrency === 'EUR' ? '200.0000' : '160.0000');
        $this->entityManager->persist($trade);
        $this->entityManager->flush();
    }
}
