<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * GET /api/accounts/{type}?status=…&kind=… : the filter bar of the accounts list.
 */
class AccountListFilterTest extends WebTestCaseBase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $user = static::createUser();
        static::authenticate($this->client, $user);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        foreach ([
            ['Banca Uno', AssetAccountKind::Checking, true],
            ['Carta prepagata', AssetAccountKind::Checking, false],
            ['Broker Uno', AssetAccountKind::Investment, true],
            ['Vecchio broker', AssetAccountKind::Investment, false],
        ] as [$name, $kind, $active]) {
            $account = new Account($user, AccountType::Asset, $name);
            $account->setAssetKind($kind);
            $account->setIsActive($active);
            $entityManager->persist($account);
        }
        $entityManager->flush();
    }

    public function testWithoutFiltersEveryAccountIsListed(): void
    {
        $this->assertSame(['Banca Uno', 'Carta prepagata', 'Broker Uno', 'Vecchio broker'], $this->names(''));
    }

    public function testStatusFilter(): void
    {
        $this->assertSame(['Banca Uno', 'Broker Uno'], $this->names('?status=active'));
        $this->assertSame(['Carta prepagata', 'Vecchio broker'], $this->names('?status=inactive'));
        $this->assertSame(['Banca Uno', 'Carta prepagata', 'Broker Uno', 'Vecchio broker'], $this->names('?status=all'));
    }

    public function testKindFilterCombinesWithStatus(): void
    {
        $this->assertSame(['Broker Uno', 'Vecchio broker'], $this->names('?kind=investment'));
        $this->assertSame(['Banca Uno'], $this->names('?kind=checking&status=active&page=1'));
    }

    public function testUnknownStatusIsRejected(): void
    {
        $this->client->request('GET', '/api/accounts/asset?status=deleted');

        $this->assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());
        $this->assertLessThan(500, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @return list<string>
     */
    private function names(string $query): array
    {
        $this->client->request('GET', '/api/accounts/asset'.$query);
        $this->assertResponseIsSuccessful();
        $items = json_decode((string) $this->client->getResponse()->getContent(), true)['items'];

        return array_map(static fn (array $account) => $account['name'], $items);
    }
}
