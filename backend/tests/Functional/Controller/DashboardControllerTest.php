<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\User;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;

/** GET /api/dashboard (computed by DashboardService) and PUT /api/dashboard/accounts. */
class DashboardControllerTest extends WebTestCaseBase
{
    public function testReturnsTheDashboardOfThePeriod(): void
    {
        $client = static::createClient();
        $user = static::createUser();
        $this->assetAccount($user, 'Checking', '250.0000');
        static::authenticate($client, $user);

        $client->request('GET', '/api/dashboard?from=2026-09-01&to=2026-09-30');

        $this->assertResponseIsSuccessful();
        $dashboard = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('250.0000', $dashboard['summary']['netWorthEnd']);
        $this->assertNull($dashboard['summary']['savingsRate']);
        $this->assertSame(['Checking'], array_column($dashboard['accounts'], 'name'));
        $this->assertCount(30, $dashboard['accounts'][0]['history']);
        $this->assertSame([], $dashboard['instruments']);
    }

    /** The listed accounts are shown, every other one hidden; another user's accounts are left alone. */
    public function testChoosesTheAccountsToShow(): void
    {
        $client = static::createClient();
        $user = static::createUser();
        $checking = $this->assetAccount($user, 'Checking', '0');
        $this->assetAccount($user, 'Broker', '0', AssetAccountKind::Investment);
        $notMine = $this->assetAccount(static::createUser(), 'Not mine', '0');
        static::authenticate($client, $user);

        $client->request('PUT', '/api/dashboard/accounts', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'visibleAccountIds' => [(string) $checking->getId()],
        ]));
        $this->assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/dashboard?from=2026-09-01&to=2026-09-30');
        $dashboard = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame(['Checking'], array_column($dashboard['accounts'], 'name'));

        $client->request('GET', '/api/accounts/asset?pageSize=-1');
        $accounts = json_decode((string) $client->getResponse()->getContent(), true)['items'];
        $this->assertSame(['Checking' => true, 'Broker' => false], array_column($accounts, 'showOnDashboard', 'name'));

        $notMine = static::getContainer()->get(EntityManagerInterface::class)->find(Account::class, $notMine->getId());
        $this->assertTrue($notMine->isShownOnDashboard());
    }

    public function testInvalidIdsAreRejected(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('PUT', '/api/dashboard/accounts', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'visibleAccountIds' => ['not-a-uuid'],
        ]));

        $this->assertResponseStatusCodeSame(422);
    }

    public function testInvalidRangeIsRejected(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('GET', '/api/dashboard?from=2026-09-30&to=2026-09-01');
        $this->assertResponseStatusCodeSame(400);

        $client->request('GET', '/api/dashboard?from=not-a-date');
        $this->assertResponseStatusCodeSame(400);
    }

    public function testRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/dashboard');

        $this->assertResponseStatusCodeSame(401);
    }

    private function assetAccount(User $user, string $name, string $openingBalance, AssetAccountKind $kind = AssetAccountKind::Checking): Account
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $account = new Account($user, AccountType::Asset, $name);
        $account->setAssetKind($kind);
        $account->setOpeningBalance($openingBalance);
        $entityManager->persist($account);
        $entityManager->flush();

        return $account;
    }
}
