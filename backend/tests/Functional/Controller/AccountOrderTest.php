<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\User;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * PUT /api/accounts/{id}/position: the order the user drags the asset accounts
 * into, followed by the accounts list and the dashboard.
 */
class AccountOrderTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private User $user;
    /** @var array<string, string> name => id */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = static::createUser();
        static::authenticate($this->client, $this->user);

        foreach (['Banca Uno', 'Carta prepagata', 'Broker Uno', 'Vecchio broker'] as $name) {
            $this->ids[$name] = $this->createAccount($this->user, AccountType::Asset, $name, $name !== 'Vecchio broker');
        }
    }

    public function testAccountsStartInCreationOrder(): void
    {
        $this->assertSame(['Banca Uno', 'Carta prepagata', 'Broker Uno', 'Vecchio broker'], $this->listedNames());
    }

    public function testMoveAfterAndBefore(): void
    {
        $this->move('Banca Uno', ['afterId' => $this->ids['Broker Uno']]);
        $this->assertSame(['Carta prepagata', 'Broker Uno', 'Banca Uno', 'Vecchio broker'], $this->listedNames());

        $this->move('Vecchio broker', ['beforeId' => $this->ids['Carta prepagata']]);
        $this->assertSame(['Vecchio broker', 'Carta prepagata', 'Broker Uno', 'Banca Uno'], $this->listedNames());
    }

    /** On the default "active" filter the inactive account is hidden, and keeps its place among the others. */
    public function testMoveWithinAFilteredListKeepsHiddenAccountsInPlace(): void
    {
        $this->move('Broker Uno', ['beforeId' => $this->ids['Banca Uno']]);

        $this->assertSame(['Broker Uno', 'Banca Uno', 'Carta prepagata'], $this->listedNames('?status=active'));
        $this->assertSame(['Broker Uno', 'Banca Uno', 'Carta prepagata', 'Vecchio broker'], $this->listedNames());
    }

    public function testNewAccountGoesAfterTheOrderedOnes(): void
    {
        $this->move('Carta prepagata', ['beforeId' => $this->ids['Banca Uno']]);
        $this->createAccount($this->user, AccountType::Asset, 'Nuovo');

        $this->assertSame(['Carta prepagata', 'Banca Uno', 'Broker Uno', 'Vecchio broker', 'Nuovo'], $this->listedNames());
    }

    public function testDashboardFollowsTheOrder(): void
    {
        $this->move('Banca Uno', ['afterId' => $this->ids['Broker Uno']]);

        $this->client->request('GET', '/api/dashboard?from=2026-09-01&to=2026-09-30');
        $this->assertResponseIsSuccessful();
        $dashboard = json_decode((string) $this->client->getResponse()->getContent(), true);

        // The inactive account is never on the dashboard.
        $this->assertSame(['Carta prepagata', 'Broker Uno', 'Banca Uno'], array_column($dashboard['accounts'], 'name'));
    }

    public function testNeighbourMustBeAnAccountOfTheSameTypeAndUser(): void
    {
        $expense = $this->createAccount($this->user, AccountType::Expense, 'Supermercato');
        $this->move('Banca Uno', ['afterId' => $expense]);
        $this->assertResponseStatusCodeSame(400);

        $otherUsersAccount = $this->createAccount(static::createUser(), AccountType::Asset, 'Altrui');
        $this->move('Banca Uno', ['afterId' => $otherUsersAccount]);
        $this->assertResponseStatusCodeSame(400);

        $this->assertSame(['Banca Uno', 'Carta prepagata', 'Broker Uno', 'Vecchio broker'], $this->listedNames());
    }

    public function testExactlyOneNeighbourIsRequired(): void
    {
        $this->move('Banca Uno', []);
        $this->assertResponseStatusCodeSame(422);

        $this->move('Banca Uno', ['afterId' => $this->ids['Carta prepagata'], 'beforeId' => $this->ids['Broker Uno']]);
        $this->assertResponseStatusCodeSame(422);
    }

    /**
     * @param array<string, string> $payload
     */
    private function move(string $name, array $payload): void
    {
        $this->client->request(
            'PUT',
            '/api/accounts/'.$this->ids[$name].'/position',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload),
        );
    }

    private function createAccount(User $user, AccountType $type, string $name, bool $active = true): string
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $account = new Account($user, $type, $name);
        if ($type === AccountType::Asset) {
            $account->setAssetKind(AssetAccountKind::Checking);
        }
        $account->setIsActive($active);
        $entityManager->persist($account);
        $entityManager->flush();

        return $account->getId()->toRfc4122();
    }

    /**
     * @return list<string>
     */
    private function listedNames(string $query = ''): array
    {
        $this->client->request('GET', '/api/accounts/asset'.$query);
        $this->assertResponseIsSuccessful();
        $items = json_decode((string) $this->client->getResponse()->getContent(), true)['items'];

        return array_map(static fn (array $account) => $account['name'], $items);
    }
}
