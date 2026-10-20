<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Account;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentInstrumentType;
use App\Entity\InvestmentPrice;
use App\Entity\InvestmentTransaction;
use App\Entity\InvestmentTransactionType;
use App\Entity\User;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The accounts list and an account's page show the balance at the end of a
 * day: the last day of the period the app has selected (`?on=`), else today.
 * What is dated after it isn't there yet.
 */
class AccountBalanceOnTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = static::createUser();
        static::authenticate($this->client, $this->user);
    }

    public function testTheBalanceIsAtTheEndOfTheDayAsked(): void
    {
        $wallet = $this->request('POST', '/api/accounts/asset', [
            'type' => 'asset', 'name' => 'Wallet', 'currencyCode' => 'EUR', 'assetKind' => 'checking',
            'openingBalance' => '1000', 'openingBalanceDate' => '2026-01-01',
        ])['id'];
        $nextYear = (new \DateTimeImmutable('first day of january next year'))->format('Y-m-d');
        $this->request('POST', '/api/transactions', [
            'description' => 'Rent', 'sourceAccountId' => $wallet, 'destinationAccountId' => null,
            'occurredAt' => $nextYear.'T09:00:00', 'amount' => '800',
        ]);

        // Today: next year's rent isn't there yet.
        $this->assertSame('1000.0000', $this->request('GET', "/api/accounts/$wallet")['currentBalance']);
        $atNextYear = $this->request('GET', "/api/accounts/$wallet?on=$nextYear");
        $this->assertSame(['200.0000', $nextYear], [$atNextYear['currentBalance'], $atNextYear['balanceDate']]);
        // Before the opening balance's date, not even that.
        $this->assertSame('0.0000', $this->request('GET', '/api/accounts/asset?on=2025-12-31')['items'][0]['currentBalance']);
    }

    public function testHoldingsAreValuedAtThatDaysPrice(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $broker = $entityManager->find(Account::class, $this->request('POST', '/api/accounts/asset', [
            'type' => 'asset', 'name' => 'Broker', 'currencyCode' => 'EUR', 'assetKind' => 'investment', 'openingBalance' => '1000',
        ])['id']);
        $etf = new InvestmentInstrument($this->user, 'ETF', InvestmentInstrumentType::Etf);
        $entityManager->persist($etf);
        $entityManager->persist(new InvestmentTransaction($this->user, $broker, $etf, InvestmentTransactionType::Buy, '10', '50', new \DateTimeImmutable('2026-03-10 10:00')));
        $entityManager->persist(new InvestmentPrice($etf, new \DateTimeImmutable('2026-03-10'), '50.0000'));
        $entityManager->persist(new InvestmentPrice($etf, new \DateTimeImmutable('2026-06-30'), '60.0000'));
        $entityManager->flush();
        $id = $broker->getId()->toRfc4122();

        $before = $this->request('GET', "/api/accounts/$id?on=2026-03-09");
        $this->assertSame(['1000.0000', '0.0000'], [$before['currentBalance'], $before['investedValue']]);

        $april = $this->request('GET', "/api/accounts/$id?on=2026-04-30");
        $this->assertSame(['500.0000', '500.0000', '0.0000'], [$april['currentBalance'], $april['investedValue'], $april['gainAmount']]);

        $july = $this->request('GET', "/api/accounts/$id?on=2026-07-01");
        $this->assertSame(['600.0000', '100.0000'], [$july['investedValue'], $july['gainAmount']]);
    }

    public function testADayThatDoesntExistIsRefused(): void
    {
        $this->request('GET', '/api/accounts/asset?on=2026-02-30');

        $this->assertResponseStatusCodeSame(400);
    }

    private function request(string $method, string $uri, ?array $body = null): array
    {
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], null === $body ? null : (string) json_encode($body));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
