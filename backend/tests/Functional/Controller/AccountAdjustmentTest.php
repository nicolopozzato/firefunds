<?php

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * An asset account's other changes to its balance (AccountAdjustment), kept
 * from its form: they move the balance from their day on, are never a
 * transaction, and so are neither income nor expenses.
 */
class AccountAdjustmentTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private Connection $connection;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->user = static::createUser();
        static::authenticate($this->client, $this->user);
    }

    public function testTheFormKeepsTheListItSends(): void
    {
        $account = $this->request('POST', '/api/accounts/asset', $this->wallet([
            ['date' => '2026-10-05', 'amount' => '20', 'description' => 'Found in a drawer'],
            ['date' => '2026-10-02', 'amount' => '-150.50'],
        ]));
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('-130.5000', $account['currentBalance']);
        $this->assertSame(
            [['2026-10-02', '-150.5000', null], ['2026-10-05', '20.0000', 'Found in a drawer']],
            array_map(static fn (array $a) => [$a['date'], $a['amount'], $a['description']], $account['adjustments'])
        );
        [$kept, $removed] = array_column($account['adjustments'], 'id');

        // One changed, one left out, one new.
        $account = $this->request('PUT', '/api/accounts/'.$account['id'], $this->wallet([
            ['id' => $kept, 'date' => '2026-10-03', 'amount' => '-100', 'description' => ' '],
            ['date' => '2026-10-07', 'amount' => '10', 'description' => 'Correction'],
        ]));
        $this->assertResponseIsSuccessful();
        $this->assertSame('-90.0000', $account['currentBalance']);
        $this->assertSame(
            [[$kept, '2026-10-03', '-100.0000', null], ['2026-10-07', '10.0000', 'Correction']],
            [array_values($account['adjustments'][0]), array_values(array_diff_key($account['adjustments'][1], ['id' => true]))]
        );
        $this->assertNotNull($this->connection->fetchOne('SELECT deleted_at FROM account_adjustment WHERE id = ?', [$removed]));

        // Not sent at all: left as they are.
        $body = $this->wallet([]);
        unset($body['adjustments']);
        $this->assertCount(2, $this->request('PUT', '/api/accounts/'.$account['id'], $body)['adjustments']);
    }

    public function testTheBalanceChangesFromTheirDay(): void
    {
        $id = $this->request('POST', '/api/accounts/asset', $this->wallet([
            ['date' => '2026-10-02', 'amount' => '20'],
            ['date' => '2026-10-04', 'amount' => '-150'],
        ], openingBalance: '100'))['id'];

        $history = $this->request('GET', "/api/accounts/$id/balance-history?from=2026-10-01&to=2026-10-04");

        $this->assertSame(['100.0000', '120.0000', '120.0000', '-30.0000'], array_column($history, 'balance'));
    }

    public function testTheyAreNeitherTransactionsNorIncomeOrExpenses(): void
    {
        $id = $this->request('POST', '/api/accounts/asset', $this->wallet([
            ['date' => '2026-09-10', 'amount' => '500'],
            ['date' => '2026-09-20', 'amount' => '-80'],
        ]))['id'];

        $this->assertSame(0, $this->request('GET', '/api/transactions?kind=all&from=2026-09-01&to=2026-09-30')['total']);
        $summary = $this->request('GET', '/api/dashboard?from=2026-09-01&to=2026-09-30')['summary'];
        $this->assertSame(['0.0000', '0.0000', '420.0000'], [$summary['income'], $summary['expenses'], $summary['netWorthChange']]);
        $report = $this->request('GET', '/api/reports/income-expenses?from=2026-09-01&to=2026-09-30&by=month');
        $this->assertSame([], $report['income']['rows']);
        $this->assertSame([], $report['expenses']['rows']);
        $this->assertSame('420.0000', $this->request('GET', "/api/accounts/$id")['currentBalance']);
    }

    public function testEachFieldIsChecked(): void
    {
        $violations = $this->request('POST', '/api/accounts/asset', $this->wallet([
            ['date' => '2026-02-31', 'amount' => '1e3', 'description' => str_repeat('x', 256)],
            ['amount' => '5'],
        ]))['violations'] ?? [];

        $this->assertResponseStatusCodeSame(422);
        $this->assertEqualsCanonicalizing([
            'adjustments[0][date]' => 'invalid_date',
            'adjustments[0][amount]' => 'invalid_number',
            'adjustments[0][description]' => 'too_long',
            'adjustments[1][date]' => 'invalid',
        ], array_column($violations, 'code', 'propertyPath'));
    }

    public function testAnotherUsersAdjustmentCantBeTakenOver(): void
    {
        $theirs = $this->request('POST', '/api/accounts/asset', $this->wallet([['date' => '2026-10-01', 'amount' => '7']]))['adjustments'][0]['id'];

        static::authenticate($this->client, static::createUser());
        $account = $this->request('POST', '/api/accounts/asset', $this->wallet([['id' => $theirs, 'date' => '2026-10-01', 'amount' => '9']]));

        $this->assertNotSame($theirs, $account['adjustments'][0]['id']);
        $this->assertSame('7.0000', $this->connection->fetchOne('SELECT amount FROM account_adjustment WHERE id = ?', [$theirs]));
    }

    /**
     * @param array<array<string, string>> $adjustments
     * @return array<string, mixed>
     */
    private function wallet(array $adjustments, string $openingBalance = '0'): array
    {
        return [
            'type' => 'asset', 'name' => 'Wallet', 'currencyCode' => 'EUR', 'assetKind' => 'checking',
            'openingBalance' => $openingBalance, 'adjustments' => $adjustments,
        ];
    }

    private function request(string $method, string $uri, ?array $body = null): array
    {
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], null === $body ? null : (string) json_encode($body));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
