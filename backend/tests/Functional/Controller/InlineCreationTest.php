<?php

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Accounts, categories and tags written in the transaction form instead of
 * picked: found by name (ignoring case) or created, in the same save as the
 * transaction. A refused transaction creates nothing, so saving again never
 * makes duplicates.
 */
class InlineCreationTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private Connection $connection;
    private User $user;
    private string $checking;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->user = static::createUser();
        static::authenticate($this->client, $this->user);
        $this->checking = $this->request('POST', '/api/accounts/asset', [
            'type' => 'asset', 'name' => 'Checking', 'currencyCode' => 'EUR', 'assetKind' => 'checking',
        ])['id'];
    }

    public function testNamesAreCreatedOnceThenFound(): void
    {
        $first = $this->withdrawal(['newDestinationAccountName' => 'Coop', 'newCategoryName' => 'Groceries', 'newTagNames' => ['Home']]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame(['Coop', 'Groceries', ['Home']], [$first['destinationAccount']['name'], $first['categoryName'], array_column($first['tags'], 'name')]);

        // Other case, spaces around: the same ones.
        $second = $this->withdrawal(['newDestinationAccountName' => ' coop ', 'newCategoryName' => 'GROCERIES', 'newTagNames' => ['home', 'Trip']]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame($first['destinationAccount']['id'], $second['destinationAccount']['id']);
        $this->assertSame($first['categoryId'], $second['categoryId']);
        $this->assertSame([1, 1, 2], $this->counts());
    }

    public function testARefusedTransactionCreatesNothing(): void
    {
        // Refused by validation (a year out of range)...
        $this->withdrawal(['newDestinationAccountName' => 'Coop', 'newCategoryName' => 'Groceries', 'newTagNames' => ['Home']], '1960-01-01T10:00:00');
        $this->assertResponseStatusCodeSame(422);
        // ...or by the service (a revenue account can't pay an expense account).
        $expense = $this->request('POST', '/api/accounts/expense', ['type' => 'expense', 'name' => 'Shop'])['id'];
        $this->request('POST', '/api/transactions', $this->body([
            'sourceAccountId' => null, 'newSourceAccountName' => 'Salary', 'destinationAccountId' => $expense,
            'newCategoryName' => 'Groceries', 'newTagNames' => ['Home'],
        ]));
        $this->assertResponseStatusCodeSame(400);

        $this->assertSame([1, 0, 0], $this->counts(), 'only the Shop account, created on its own');
    }

    public function testAnIdOrANameNotBoth(): void
    {
        $response = $this->withdrawal(['newCategoryName' => 'Groceries', 'categoryId' => '01890000-0000-7000-8000-000000000000']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(['newCategoryName'], array_column($response['violations'], 'propertyPath'));
    }

    /** @param array<string, mixed> $fields */
    private function withdrawal(array $fields, string $occurredAt = '2026-10-10T10:00:00'): array
    {
        return $this->request('POST', '/api/transactions', $this->body(['sourceAccountId' => $this->checking, 'destinationAccountId' => null] + $fields, $occurredAt));
    }

    /** @param array<string, mixed> $fields */
    private function body(array $fields, string $occurredAt = '2026-10-10T10:00:00'): array
    {
        return $fields + ['description' => 'Shopping', 'occurredAt' => $occurredAt, 'amount' => '20'];
    }

    /** @return array{0: int, 1: int, 2: int} expense and revenue accounts, categories, tags of the user */
    private function counts(): array
    {
        $user = $this->user->getId()->toRfc4122();

        return [
            (int) $this->connection->fetchOne("SELECT COUNT(*) FROM account WHERE user_id = ? AND type <> 'asset'", [$user]),
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM category WHERE user_id = ?', [$user]),
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tag WHERE user_id = ?', [$user]),
        ];
    }

    private function request(string $method, string $uri, ?array $body = null): array
    {
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], null === $body ? null : (string) json_encode($body));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
