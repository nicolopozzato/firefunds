<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\Currency;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentInstrumentType;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * GET /api/investment-transactions?from=…&to=… : the list follows the selected
 * timeframe, or shows everything when no range is given.
 */
class InvestmentTransactionListRangeTest extends WebTestCaseBase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $user = static::createUser();
        static::authenticate($this->client, $user);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $currency = $entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']);
        if ($currency === null) {
            $currency = new Currency('EUR', 'Euro', '€', 2);
            $entityManager->persist($currency);
        }

        $account = new Account($user, AccountType::Asset, 'Broker');
        $account->setAssetKind(AssetAccountKind::Investment);
        $account->setCurrency($currency);
        $entityManager->persist($account);

        $instrument = new InvestmentInstrument($user, 'Test ETF', InvestmentInstrumentType::Etf);
        $entityManager->persist($instrument);
        $entityManager->flush();

        foreach (['2026-08-31T10:00:00+00:00', '2026-09-01T10:00:00+00:00', '2026-09-30T21:00:00+00:00', '2026-10-01T10:00:00+00:00'] as $occurredAt) {
            $this->client->request('POST', '/api/investment-transactions', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'accountId' => (string) $account->getId(),
                'instrumentId' => (string) $instrument->getId(),
                'type' => 'buy',
                'quantity' => '1',
                'unitPrice' => '100',
                'occurredAt' => $occurredAt,
            ]));
            $this->assertResponseStatusCodeSame(201);
        }
    }

    public function testWithoutARangeEveryTradeIsListed(): void
    {
        $this->assertSame(['2026-10-01', '2026-09-30', '2026-09-01', '2026-08-31'], $this->listedDays(''));
    }

    public function testRangeIncludesTheWholeLastDay(): void
    {
        $this->assertSame(['2026-09-30', '2026-09-01'], $this->listedDays('?from=2026-09-01&to=2026-09-30'));
    }

    /** Each trade here: 1 x 100, no costs. */
    public function testDistributionsFollowTheRange(): void
    {
        $this->client->request('GET', '/api/investment-transactions/distributions?from=2026-09-01&to=2026-09-30');
        $this->assertResponseIsSuccessful();
        $distributions = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertSame([['etf', 'etf', '200.0000']], $this->rows($distributions['instrumentTypes']));
        $this->assertSame([['Test ETF', '200.0000']], array_map(static fn ($row) => [$row['name'], $row['total']], $distributions['instruments']));
        $this->assertSame([['Broker', '200.0000']], array_map(static fn ($row) => [$row['name'], $row['total']], $distributions['accounts']));
    }

    public function testInvalidRangeIsA400(): void
    {
        $this->client->request('GET', '/api/investment-transactions?from=not-a-date');

        $this->assertResponseStatusCodeSame(400);
    }

    /**
     * @param list<array{id: string, name: string, total: string}> $entries
     * @return list<list<string>>
     */
    private function rows(array $entries): array
    {
        return array_map(static fn (array $row) => [$row['id'], $row['name'], $row['total']], $entries);
    }

    /**
     * @return list<string>
     */
    private function listedDays(string $query): array
    {
        $this->client->request('GET', '/api/investment-transactions'.$query);
        $this->assertResponseIsSuccessful();
        $items = json_decode((string) $this->client->getResponse()->getContent(), true)['items'];

        return array_map(static fn (array $trade) => substr($trade['occurredAt'], 0, 10), $items);
    }
}
