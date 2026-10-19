<?php

namespace App\Tests\Functional\Controller;

use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentInstrumentType;
use App\Entity\InvestmentPrice;
use App\Entity\User;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Endpoints that had no test of their own: an account's daily balances and
 * value, an instrument's prices, editing a transaction (what it clears, what
 * its history records) and a trade's history.
 */
class HistoryAndEditingTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = static::createUser();
        static::authenticate($this->client, $this->user);
    }

    public function testBalanceHistoryHasOneValuePerDay(): void
    {
        $wallet = $this->request('POST', '/api/accounts/asset', [
            'type' => 'asset', 'name' => 'Wallet', 'currencyCode' => 'EUR', 'assetKind' => 'checking',
            'openingBalance' => '100', 'openingBalanceDate' => '2026-10-01',
        ])['id'];
        $this->request('POST', '/api/transactions', [
            'description' => 'Groceries', 'sourceAccountId' => $wallet, 'destinationAccountId' => null,
            'occurredAt' => '2026-10-05T23:30:00', 'amount' => '30',
        ]);

        $history = $this->request('GET', "/api/accounts/$wallet/balance-history?from=2026-10-03&to=2026-10-06");

        $this->assertSame([
            ['date' => '2026-10-03', 'balance' => '100.0000'],
            ['date' => '2026-10-04', 'balance' => '100.0000'],
            ['date' => '2026-10-05', 'balance' => '70.0000'],
            ['date' => '2026-10-06', 'balance' => '70.0000'],
        ], $history);
    }

    public function testValueHistoryAddsCashAndInvestments(): void
    {
        $broker = $this->request('POST', '/api/accounts/asset', [
            'type' => 'asset', 'name' => 'Broker', 'currencyCode' => 'EUR', 'assetKind' => 'investment',
        ])['id'];

        $history = $this->request('GET', "/api/accounts/$broker/value-history?from=2026-10-01&to=2026-10-03");

        $this->assertSame(['2026-10-01', '2026-10-02', '2026-10-03'], array_column($history, 'date'));
        foreach ($history as $point) {
            $this->assertSame(bcadd($point['available'], $point['invested'], 4), bcadd($point['total'], '0', 4));
        }
    }

    public function testPricesOfAnInstrumentInARange(): void
    {
        $instrument = new InvestmentInstrument($this->user, 'ETF', InvestmentInstrumentType::Etf);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($instrument);
        foreach (['2026-09-30' => '99.5000', '2026-10-01' => '100.0000', '2026-10-02' => '101.2500'] as $day => $price) {
            $entityManager->persist(new InvestmentPrice($instrument, new \DateTimeImmutable($day), $price));
        }
        $entityManager->flush();

        $prices = $this->request('GET', '/api/investment-instruments/'.$instrument->getId().'/prices?from=2026-10-01&to=2026-10-02');

        $this->assertSame([['2026-10-01', '100.0000'], ['2026-10-02', '101.2500']], array_map(static fn (array $p) => [$p['date'], $p['price']], $prices));
    }

    public function testEditingATransactionClearsWhatIsLeftOutAndRecordsIt(): void
    {
        $wallet = $this->request('POST', '/api/accounts/asset', ['type' => 'asset', 'name' => 'Wallet', 'currencyCode' => 'EUR', 'assetKind' => 'checking'])['id'];
        $category = $this->request('POST', '/api/categories', ['name' => 'Food'])['id'];
        $tag = $this->request('POST', '/api/tags', ['name' => 'Trip'])['id'];
        $payment = [
            'description' => 'Dinner', 'sourceAccountId' => $wallet, 'destinationAccountId' => null,
            'occurredAt' => '2026-10-05T20:00:00', 'amount' => '40',
        ];
        $id = $this->request('POST', '/api/transactions', $payment + ['categoryId' => $category, 'tagIds' => [$tag]])['id'];

        $edited = $this->request('PUT', "/api/transactions/$id", ['amount' => '45'] + $payment);

        $this->assertResponseIsSuccessful();
        $this->assertNull($edited['categoryId']);
        $this->assertSame([], $edited['tags']);
        $this->assertSame(0, bccomp('45', $edited['amount'], 4));

        $history = $this->request('GET', "/api/transactions/$id/audit-log");
        $this->assertEqualsCanonicalizing(['created', 'updated'], array_column($history, 'action'));
        $updated = array_values(array_filter($history, static fn (array $entry) => 'updated' === $entry['action']))[0];
        $this->assertEqualsCanonicalizing(['amount', 'category', 'tags'], array_column($updated['changes'], 'field'));
    }

    public function testAForeignAmountChangeIsRecorded(): void
    {
        $wallet = $this->request('POST', '/api/accounts/asset', ['type' => 'asset', 'name' => 'Wallet', 'currencyCode' => 'EUR', 'assetKind' => 'checking'])['id'];
        $payment = [
            'description' => 'Hotel', 'sourceAccountId' => $wallet, 'destinationAccountId' => null,
            'occurredAt' => '2026-10-05T20:00:00', 'amount' => '100', 'foreignAmount' => '110', 'foreignCurrency' => 'USD',
        ];
        $id = $this->request('POST', '/api/transactions', $payment)['id'];

        $this->request('PUT', "/api/transactions/$id", ['foreignAmount' => '112.5'] + $payment);

        $history = $this->request('GET', "/api/transactions/$id/audit-log");
        $updated = array_values(array_filter($history, static fn (array $entry) => 'updated' === $entry['action']))[0];
        $this->assertSame([['foreignAmount', '110.0000', '112.5000']], array_map(
            static fn (array $change) => [$change['field'], $change['oldValue'], $change['newValue']],
            $updated['changes'],
        ));
    }

    public function testATradeHasAHistory(): void
    {
        $broker = $this->request('POST', '/api/accounts/asset', ['type' => 'asset', 'name' => 'Broker', 'currencyCode' => 'EUR', 'assetKind' => 'investment'])['id'];
        $instrument = $this->request('POST', '/api/investment-instruments', ['name' => 'ETF', 'type' => 'etf'])['id'];
        $trade = $this->request('POST', '/api/investment-transactions', [
            'accountId' => $broker, 'instrumentId' => $instrument, 'type' => 'buy',
            'quantity' => '2', 'unitPrice' => '50', 'occurredAt' => '2026-10-05T10:00:00',
        ]);
        $this->assertResponseStatusCodeSame(201);

        $history = $this->request('GET', '/api/investment-transactions/'.$trade['id'].'/audit-log');

        $this->assertSame(['created'], array_column($history, 'action'));
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<mixed>
     */
    private function request(string $method, string $uri, ?array $body = null): array
    {
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], null === $body ? null : (string) json_encode($body));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
