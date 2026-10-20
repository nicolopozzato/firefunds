<?php

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * An account's history (GET /api/accounts/{id}/audit-log): created, what
 * each save changed (fields, and each other balance change added, changed
 * or removed), deleted.
 */
class AccountAuditLogTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = static::createUser();
        static::authenticate($this->client, $this->user);
    }

    public function testEachSaveRecordsWhatChanged(): void
    {
        $account = $this->request('POST', '/api/accounts/asset', $this->wallet('Wallet', '100', [
            ['date' => '2026-10-01', 'amount' => '20', 'description' => 'Drawer'],
            ['date' => '2026-10-02', 'amount' => '-5'],
        ]));
        [$changed, $removed] = $account['adjustments'];

        $this->request('PUT', '/api/accounts/'.$account['id'], $this->wallet('Cash', '150', [
            ['id' => $changed['id'], 'date' => '2026-10-01', 'amount' => '25', 'description' => 'Drawer'],
            ['date' => '2026-10-09', 'amount' => '7', 'description' => 'Found'],
        ]));
        // Saved again as it is: nothing to record.
        $this->request('PUT', '/api/accounts/'.$account['id'], $this->wallet('Cash', '150.00', [
            ['id' => $changed['id'], 'date' => '2026-10-01', 'amount' => '25.0', 'description' => 'Drawer'],
            ['id' => $this->request('GET', '/api/accounts/'.$account['id'])['adjustments'][1]['id'], 'date' => '2026-10-09', 'amount' => '7', 'description' => 'Found'],
        ]));

        $log = $this->request('GET', '/api/accounts/'.$account['id'].'/audit-log');

        $this->assertSame(['updated', 'created'], array_column($log, 'action'));
        $this->assertEqualsCanonicalizing([
            ['field' => 'name', 'oldValue' => 'Wallet', 'newValue' => 'Cash'],
            ['field' => 'openingBalance', 'oldValue' => '100.0000', 'newValue' => '150.0000'],
            ['field' => 'adjustment',
                'oldValue' => ['date' => '2026-10-01', 'amount' => '20.0000', 'description' => 'Drawer'],
                'newValue' => ['date' => '2026-10-01', 'amount' => '25.0000', 'description' => 'Drawer']],
            ['field' => 'adjustment', 'oldValue' => ['date' => '2026-10-02', 'amount' => '-5.0000', 'description' => null], 'newValue' => null],
            ['field' => 'adjustment', 'oldValue' => null, 'newValue' => ['date' => '2026-10-09', 'amount' => '7.0000', 'description' => 'Found']],
        ], $log[0]['changes']);
        $this->assertNotContains($removed['id'], array_column($this->request('GET', '/api/accounts/'.$account['id'])['adjustments'], 'id'));
    }

    /** The account taking over records the opening balance it got; the deleted one its deletion. */
    public function testDeletingWithATransferIsRecordedOnBothAccounts(): void
    {
        $old = $this->request('POST', '/api/accounts/asset', $this->wallet('Old', '40', []) + ['openingBalanceDate' => '2026-09-01'])['id'];
        $new = $this->request('POST', '/api/accounts/asset', $this->wallet('New', '0', []))['id'];

        $this->request('DELETE', "/api/accounts/$old?transferTo=$new");
        $this->assertResponseStatusCodeSame(204);

        $log = $this->request('GET', "/api/accounts/$new/audit-log");
        $this->assertSame(
            [['field' => 'adjustment', 'oldValue' => null, 'newValue' => ['date' => '2026-09-01', 'amount' => '40.0000', 'description' => 'Old']]],
            $log[0]['changes']
        );
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->assertSame(
            ['deleted', 'created'],
            $connection->fetchFirstColumn('SELECT action FROM account_audit_log WHERE account_id = ? ORDER BY created_at DESC, id DESC', [$old])
        );
    }

    public function testAnotherUsersHistoryIsNotFound(): void
    {
        $id = $this->request('POST', '/api/accounts/asset', $this->wallet('Mine', '0', []))['id'];

        static::authenticate($this->client, static::createUser());
        $this->request('GET', "/api/accounts/$id/audit-log");

        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<array<string, string>> $adjustments
     * @return array<string, mixed>
     */
    private function wallet(string $name, string $openingBalance, array $adjustments): array
    {
        return [
            'type' => 'asset', 'name' => $name, 'currencyCode' => 'EUR', 'assetKind' => 'checking',
            'openingBalance' => $openingBalance, 'adjustments' => $adjustments,
        ];
    }

    private function request(string $method, string $uri, ?array $body = null): array
    {
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], null === $body ? null : (string) json_encode($body));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
