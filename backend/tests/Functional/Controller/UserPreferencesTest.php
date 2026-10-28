<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Currency;
use App\Entity\User;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The preferences beyond language and formats: appearance, start page and
 * period, default account, dashboard layout. Each one left out stays as it is.
 */
class UserPreferencesTest extends WebTestCaseBase
{
    private const array BASE = ['locale' => 'it', 'theme' => 'system', 'pageSize' => 50, 'timezone' => 'Europe/Rome', 'numberFormat' => 'it-IT'];

    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = static::createUser();
        static::authenticate($this->client, $this->user);
    }

    public function testDefaultsAndSavedValues(): void
    {
        $me = $this->send('GET', '/api/auth/me');
        $this->assertSame('dashboard', $me['startPage']);
        $this->assertSame('last', $me['startPeriod']);
        $this->assertNull($me['defaultAccountId']);
        $this->assertSame(['summary', 'trend', 'expensesByCategory', 'expenseAccounts', 'revenueAccounts', 'accounts'], array_column($me['dashboardSections'], 'key'));

        $saved = $this->send('PUT', '/api/auth/me/preferences', self::BASE + [
            'density' => 'compact',
            'startPage' => 'investments',
            'startPeriod' => 'currentYear',
            'dashboardTrendView' => 'investments',
            'dashboardInvestmentUnit' => 'money',
            'dashboardSections' => [
                ['key' => 'expensesByCategory', 'visible' => true],
                ['key' => 'trend', 'visible' => false],
            ],
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSame('compact', $saved['density']);
        $this->assertSame('investments', $saved['dashboardTrendView']);
        $this->assertSame(
            ['expensesByCategory', 'trend', 'summary', 'expenseAccounts', 'revenueAccounts', 'accounts'],
            array_column($saved['dashboardSections'], 'key'),
            'the sections left out follow, in their default order',
        );
        $this->assertFalse($saved['dashboardSections'][1]['visible']);

        $this->send('PUT', '/api/auth/me/preferences', self::BASE);
        $me = $this->send('GET', '/api/auth/me');
        $this->assertSame('compact', $me['density'], 'left out = unchanged');
        $this->assertSame('currentYear', $me['startPeriod']);

        // One preference alone: the others stay as they are.
        $this->send('PUT', '/api/auth/me/preferences', ['theme' => 'dark']);
        $this->assertResponseIsSuccessful();
        $me = $this->send('GET', '/api/auth/me');
        $this->assertSame('dark', $me['theme']);
        $this->assertSame('it', $me['locale']);
        $this->assertSame('compact', $me['density']);
    }

    public function testUnknownChoicesAreRejected(): void
    {
        $this->send('PUT', '/api/auth/me/preferences', self::BASE + ['density' => 'huge']);
        $this->assertResponseStatusCodeSame(422);

        $this->send('PUT', '/api/auth/me/preferences', self::BASE + ['dashboardSections' => [['key' => 'budget', 'visible' => true]]]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testDefaultAccountMustBeAnActiveAssetAccountAndCanBeCleared(): void
    {
        $asset = $this->account(AccountType::Asset);
        $expense = $this->account(AccountType::Expense);

        $this->assertSame($asset['id'], $this->send('PUT', '/api/auth/me/preferences', self::BASE + ['defaultAccountId' => $asset['id']])['defaultAccountId']);

        $this->send('PUT', '/api/auth/me/preferences', self::BASE + ['defaultAccountId' => $expense['id']]);
        $this->assertResponseStatusCodeSame(400);

        $this->assertNull($this->send('PUT', '/api/auth/me/preferences', self::BASE + ['defaultAccountId' => ''])['defaultAccountId']);
    }

    public function testDeletedDefaultAccountIsNoLongerOffered(): void
    {
        $asset = $this->account(AccountType::Asset);
        $this->send('PUT', '/api/auth/me/preferences', self::BASE + ['defaultAccountId' => $asset['id']]);

        $this->send('DELETE', '/api/accounts/'.$asset['id']);
        $this->assertResponseIsSuccessful();

        $this->assertNull($this->send('GET', '/api/auth/me')['defaultAccountId']);
    }

    /** @return array{id: string} */
    private function account(AccountType $type): array
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $account = new Account($this->user, $type, 'Conto');
        if ($type === AccountType::Asset) {
            $account->setCurrency($entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']));
        }
        $entityManager->persist($account);
        $entityManager->flush();

        return ['id' => $account->getId()->toRfc4122()];
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $uri, ?array $body = null): array
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: $body !== null ? json_encode($body) : null);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
