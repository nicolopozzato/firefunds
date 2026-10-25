<?php

namespace App\Tests\Integration\Currency;

use App\Currency\Provider\ExchangeRateProviderInterface;
use App\Currency\Service\CurrencyConverter;
use App\Currency\Service\ExchangeRateService;
use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\Currency;
use App\Entity\ExchangeRateOverride;
use App\Entity\ExchangeRateSource;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentInstrumentType;
use App\Entity\InvestmentTransaction;
use App\Entity\InvestmentTransactionType;
use App\Entity\User;
use App\Investment\Service\PriceCalendar;
use App\Repository\ExchangeRateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Real database, fake provider: which currencies are fetched and from when,
 * every calendar day filled, the user's own rate over the shared one.
 */
class ExchangeRateServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ExchangeRateRepository $repository;
    private User $user;
    /** @var list<array{string[], string, string}> */
    private array $asked = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(ExchangeRateRepository::class);

        $this->user = new User();
        $this->user->setFirstName('Rates');
        $this->user->setLastName('Test');
        $this->user->setEmail(\sprintf('rates-%s@example.test', uniqid()));
        $this->user->setPassword('hashed');
        $this->entityManager->persist($this->user);

        $eur = $this->entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']);
        $broker = new Account($this->user, AccountType::Asset, 'Broker');
        $broker->setAssetKind(AssetAccountKind::Investment);
        $broker->setCurrency($eur);
        $this->entityManager->persist($broker);

        $instrument = new InvestmentInstrument($this->user, 'S&P 500', InvestmentInstrumentType::Etf);
        $instrument->setCurrency('USD');
        $this->entityManager->persist($instrument);
        $this->entityManager->persist(new InvestmentTransaction(
            user: $this->user,
            account: $broker,
            instrument: $instrument,
            type: InvestmentTransactionType::Buy,
            quantity: '1',
            unitPrice: '100.0000',
            occurredAt: new \DateTimeImmutable('2026-10-14 10:00', new \DateTimeZone('UTC')),
        ));
        $this->entityManager->flush();
    }

    public function testAUserWithOneCurrencyNeedsNoRates(): void
    {
        $other = new User();
        $other->setFirstName('One');
        $other->setLastName('Currency');
        $other->setEmail(\sprintf('one-%s@example.test', uniqid()));
        $other->setPassword('hashed');
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        $this->assertSame([], $this->repository->neededCurrencies($other));
        $this->assertSame(['USD' => '2026-10-14'], $this->repository->neededCurrencies($this->user));
    }

    public function testEveryCalendarDayThroughYesterdayGetsARateCarriedOverWhereThereIsNone(): void
    {
        $unresolved = $this->service(['USD' => ['2026-10-14' => '1.10000000', '2026-10-15' => '1.12000000', '2026-10-19' => '1.20000000']])->sync($this->user);

        $this->assertSame([], $unresolved);
        $rows = $this->repository->findEffective($this->user, ['USD'], new \DateTimeImmutable('2026-10-14'), new \DateTimeImmutable('2026-10-22'))['USD'];
        $this->assertSame(['2026-10-14', '2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18', '2026-10-19', '2026-10-20', '2026-10-21'], array_keys($rows), 'through yesterday');
        $this->assertSame('1.12000000', $rows['2026-10-17']['rate']);
        $this->assertSame('filled', $rows['2026-10-17']['source']);
        $this->assertSame('ecb', $rows['2026-10-19']['source']);
        $this->assertSame('1.20000000', $rows['2026-10-21']['rate']);
        $this->assertSame([['USD'], '2026-10-14', '2026-10-21'], $this->asked[0]);
    }

    public function testASecondRunOnlyChecksTheLastDaysAndCorrectsTheCarriedOverOnes(): void
    {
        $this->service(['USD' => ['2026-10-14' => '1.10000000']])->sync($this->user);
        $this->asked = [];

        // The rate of the 20th is published late.
        $this->service(['USD' => ['2026-10-20' => '1.25000000']])->sync($this->user);

        $this->assertSame([['USD'], '2026-10-14', '2026-10-21'], $this->asked[0], 'the week before the newest rate covers all of it here');
        $rows = $this->repository->findEffective($this->user, ['USD'], new \DateTimeImmutable('2026-10-14'), new \DateTimeImmutable('2026-10-21'))['USD'];
        $this->assertSame(['rate' => '1.25000000', 'source' => 'ecb', 'original' => '1.25000000'], $rows['2026-10-20']);
        $this->assertSame('1.25000000', $rows['2026-10-21']['rate'], 'carried over from it now');
    }

    public function testACurrencyNobodyPublishesIsReported(): void
    {
        $this->assertSame(['USD'], $this->service([])->sync($this->user));
    }

    public function testTheUsersOwnRateWinsOverTheSharedOneOnThatDayOnly(): void
    {
        $this->service(['USD' => ['2026-10-14' => '1.10000000', '2026-10-15' => '1.12000000']])->sync($this->user);
        $this->entityManager->persist(new ExchangeRateOverride($this->user, 'USD', new \DateTimeImmutable('2026-10-15'), '1.50000000'));
        $this->entityManager->flush();

        $rows = $this->repository->findEffective($this->user, ['USD'], new \DateTimeImmutable('2026-10-14'), new \DateTimeImmutable('2026-10-16'))['USD'];

        $this->assertSame('1.10000000', $rows['2026-10-14']['rate']);
        $this->assertSame(['rate' => '1.50000000', 'source' => 'manual', 'original' => '1.12000000'], $rows['2026-10-15']);
        $this->assertSame('1.12000000', $rows['2026-10-16']['rate'], 'the day after is the shared rate, not the correction carried on');

        $table = (new CurrencyConverter($this->repository))->tableFor($this->user, ['USD', 'EUR'], new \DateTimeImmutable('2026-10-14'), new \DateTimeImmutable('2026-10-16'));
        $this->assertSame('100.0000000000', $table->convert('150', 'USD', 'EUR', '2026-10-15'));
    }

    /**
     * @param array<string, array<string, string>> $published currency => (day => rate)
     */
    private function service(array $published): ExchangeRateService
    {
        $provider = new class($published, $this->asked) implements ExchangeRateProviderInterface {
            /** @param array<string, array<string, string>> $published */
            public function __construct(private array $published, private array &$asked)
            {
            }

            public function source(): ExchangeRateSource
            {
                return ExchangeRateSource::Ecb;
            }

            public function getRates(array $currencies, \DateTimeImmutable $from, \DateTimeImmutable $to): array
            {
                $this->asked[] = [$currencies, $from->format('Y-m-d'), $to->format('Y-m-d')];
                $rates = [];
                foreach ($currencies as $currency) {
                    $byDay = array_filter($this->published[$currency] ?? [], static fn ($_, $day) => $day >= $from->format('Y-m-d') && $day <= $to->format('Y-m-d'), \ARRAY_FILTER_USE_BOTH);
                    if ($byDay !== []) {
                        $rates[$currency] = $byDay;
                    }
                }

                return $rates;
            }
        };

        return new ExchangeRateService($this->repository, [$provider], new PriceCalendar(new MockClock('2026-10-22 08:00:00 UTC'), 'Europe/Rome'), new NullLogger());
    }
}
