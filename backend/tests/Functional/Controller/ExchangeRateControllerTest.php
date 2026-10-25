<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\Currency;
use App\Entity\ExchangeRateSource;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentInstrumentType;
use App\Entity\InvestmentTransaction;
use App\Entity\InvestmentTransactionType;
use App\Entity\User;
use App\Investment\Service\PriceCalendar;
use App\Repository\ExchangeRateRepository;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The rates as the user sees them (what 1 unit of a currency is worth in
 * theirs) and their own corrections, which no one else sees.
 */
class ExchangeRateControllerTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->user = static::createUser();
        static::authenticate($this->client, $this->user);
        $this->holdDollars($this->user);

        // One euro bought 1.10 dollars on the 10th, 1.25 on the 11th.
        static::getContainer()->get(ExchangeRateRepository::class)->upsert('USD', [
            '2026-10-10' => ['1.10000000', ExchangeRateSource::Ecb],
            '2026-10-11' => ['1.25000000', ExchangeRateSource::Ecb],
            '2026-10-12' => ['1.25000000', ExchangeRateSource::Filled],
        ]);
    }

    public function testTheRatesAreInTheUsersCurrency(): void
    {
        $overview = $this->request('GET', '/api/exchange-rates');
        $this->assertSame('EUR', $overview['currency']);
        $this->assertSame(['USD'], array_column($overview['currencies'], 'currency'));

        $days = $this->request('GET', '/api/exchange-rates/USD?from=2026-10-10&to=2026-10-12');
        $this->assertSame(['2026-10-12', '2026-10-11', '2026-10-10'], array_column($days, 'date'), 'newest first');
        $this->assertSame('0.90909091', $days[2]['rate'], '1 USD in euros: 1 / 1.10');
        $this->assertSame('0.8000', $days[1]['rate'], '1 / 1.25');
        $this->assertSame(['filled', 'ecb', 'ecb'], array_column($days, 'source'));
    }

    public function testTheUsersOwnRateIsTheirsAloneAndGoesBackWhenTakenAway(): void
    {
        $set = $this->request('PUT', '/api/exchange-rates/usd/2026-10-11', ['rate' => '0.9']);
        $this->assertResponseIsSuccessful();
        $this->assertSame(['date' => '2026-10-11', 'rate' => '0.9000', 'source' => 'manual', 'originalRate' => '0.8000'], $set);

        $other = $this->userWithDollars();
        static::authenticate($this->client, $other);
        $this->assertSame('0.8000', $this->request('GET', '/api/exchange-rates/USD?from=2026-10-11&to=2026-10-11')[0]['rate'], 'someone else still sees the shared rate');

        static::authenticate($this->client, $this->user);
        $this->client->request('DELETE', '/api/exchange-rates/USD/2026-10-11');
        $this->assertResponseStatusCodeSame(204);
        $this->assertSame('0.8000', $this->request('GET', '/api/exchange-rates/USD?from=2026-10-11&to=2026-10-11')[0]['rate']);

        $this->client->request('DELETE', '/api/exchange-rates/USD/2026-10-11');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testAnotherPreferredCurrencyChangesWhatTheRatesAreIn(): void
    {
        $this->user->setCurrency('USD');
        $this->entityManager->flush();

        $overview = $this->request('GET', '/api/exchange-rates');
        $this->assertSame('USD', $overview['currency']);
        $this->assertSame(['EUR'], array_column($overview['currencies'], 'currency'), 'the euro: the user\'s own one is left out');

        $days = $this->request('GET', '/api/exchange-rates/EUR?from=2026-10-10&to=2026-10-11');
        $this->assertSame(['1.2500', '1.1000'], array_column($days, 'rate'), '1 EUR in dollars');

        $this->request('PUT', '/api/exchange-rates/EUR/2026-10-10', ['rate' => '1.15']);
        $this->assertSame('1.1500', $this->request('GET', '/api/exchange-rates/EUR?from=2026-10-10&to=2026-10-10')[0]['rate']);
    }

    public function testEachCurrencySaysWhetherItsRatesCanBeTrusted(): void
    {
        $yesterday = static::getContainer()->get(PriceCalendar::class)->yesterday();
        $rates = static::getContainer()->get(ExchangeRateRepository::class);
        $rates->upsert('USD', [$yesterday->format('Y-m-d') => ['1.20000000', ExchangeRateSource::Filled]]);
        $instrument = new InvestmentInstrument($this->user, 'FTSE 100', InvestmentInstrumentType::Etf);
        $instrument->setCurrency('GBP');
        $this->entityManager->persist($instrument);
        $this->entityManager->flush();

        $statuses = fn () => array_column($this->request('GET', '/api/exchange-rates')['currencies'], null, 'currency');

        $this->assertSame('ok', $statuses()['USD']['status'], 'up to yesterday');
        $this->assertSame($yesterday->format('Y-m-d'), $statuses()['USD']['latestDate']);
        $this->assertSame('missing', $statuses()['GBP']['status'], 'no rate anywhere');
        $this->assertNull($statuses()['GBP']['latestDate']);

        // The user's own rate fills the gap, and never goes stale: no provider has newer ones.
        $old = $yesterday->modify('-30 days')->format('Y-m-d');
        $this->request('PUT', "/api/exchange-rates/GBP/{$old}", ['rate' => '1.15']);
        $this->assertSame('ok', $statuses()['GBP']['status']);
        $this->assertSame($old, $statuses()['GBP']['latestDate']);
    }

    public function testSharedRatesThatStoppedDaysAgoAreStale(): void
    {
        $yesterday = static::getContainer()->get(PriceCalendar::class)->yesterday();
        $rates = static::getContainer()->get(ExchangeRateRepository::class);
        // The user's own currency is the dollar: the euro's rate depends on the dollar's.
        $this->user->setCurrency('USD');
        $this->entityManager->flush();
        $statuses = fn () => array_column($this->request('GET', '/api/exchange-rates')['currencies'], null, 'currency');

        $rates->upsert('USD', [$yesterday->modify('-4 days')->format('Y-m-d') => ['1.20000000', ExchangeRateSource::Ecb]]);
        $this->assertSame('stale', $statuses()['EUR']['status'], 'the fetch is failing');
        $this->assertSame($yesterday->modify('-4 days')->format('Y-m-d'), $statuses()['EUR']['latestDate']);

        $rates->upsert('USD', [$yesterday->modify('-3 days')->format('Y-m-d') => ['1.20000000', ExchangeRateSource::Filled]]);
        $this->assertSame('ok', $statuses()['EUR']['status'], 'three days behind is still a fetch catching up');
    }

    public function testInvalidCurrenciesDatesAndRatesAreRefused(): void
    {
        $own = $this->request('PUT', '/api/exchange-rates/EUR/2026-10-10', ['rate' => '1']);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('rate_currency_invalid', $own['code']);

        $this->request('PUT', '/api/exchange-rates/XXZ/2026-10-10', ['rate' => '1']);
        $this->assertResponseStatusCodeSame(400);

        $future = $this->request('PUT', '/api/exchange-rates/USD/2999-01-01', ['rate' => '1']);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('rate_date_in_future', $future['code']);

        $this->request('PUT', '/api/exchange-rates/USD/2026-10-10', ['rate' => '0']);
        $this->assertResponseStatusCodeSame(422);
    }

    private function userWithDollars(): User
    {
        $other = static::createUser();
        $this->holdDollars($other);

        return $other;
    }

    /** An account in euros holding an instrument quoted in dollars: two currencies. */
    private function holdDollars(User $user): void
    {
        $eur = $this->entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']);
        $broker = new Account($user, AccountType::Asset, 'Broker');
        $broker->setAssetKind(AssetAccountKind::Investment);
        $broker->setCurrency($eur);
        $this->entityManager->persist($broker);
        $instrument = new InvestmentInstrument($user, 'S&P 500', InvestmentInstrumentType::Etf);
        $instrument->setCurrency('USD');
        $this->entityManager->persist($instrument);
        $this->entityManager->persist(new InvestmentTransaction(
            user: $user,
            account: $broker,
            instrument: $instrument,
            type: InvestmentTransactionType::Buy,
            quantity: '1',
            unitPrice: '100.0000',
            occurredAt: new \DateTimeImmutable('2026-10-10 10:00', new \DateTimeZone('UTC')),
        ));
        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $uri, ?array $body = null): array
    {
        $this->client->jsonRequest($method, $uri, $body ?? []);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
