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
 * The trade's amount (what the broker charged) over HTTP, as the form sends it.
 */
class InvestmentTransactionAmountTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private string $accountId;
    private string $instrumentId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $user = static::createUser();
        static::authenticate($this->client, $user);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $currency = $entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']);
        if ($currency === null) {
            $currency = new Currency('EUR', 'Euro', '€', 2, true);
            $entityManager->persist($currency);
        }

        $account = new Account($user, AccountType::Asset, 'Broker');
        $account->setAssetKind(AssetAccountKind::Investment);
        $account->setCurrency($currency);
        $entityManager->persist($account);

        $instrument = new InvestmentInstrument($user, 'Test ETF', InvestmentInstrumentType::Etf);
        $entityManager->persist($instrument);
        $entityManager->flush();

        $this->accountId = (string) $account->getId();
        $this->instrumentId = (string) $instrument->getId();
    }

    public function testCreateKeepsTheAmountTheBrokerCharged(): void
    {
        $response = $this->post(['quantity' => '0.300987', 'unitPrice' => '166.12', 'amount' => '50.00']);

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('166.12', $response['unitPrice']);
        $this->assertSame('50.0000', $response['amount']);
        $this->assertSame('50.0000', $response['totalAmount']);
    }

    public function testAmountDefaultsToQuantityTimesUnitPrice(): void
    {
        $response = $this->post(['quantity' => '0.12', 'unitPrice' => '100']);

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('12.0000', $response['amount']);
    }

    public function testImplausibleAmountIsA400(): void
    {
        $response = $this->post(['quantity' => '0.12', 'unitPrice' => '100', 'amount' => '120']);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('too far from quantity × unit price', $response['message'] ?? json_encode($response));
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function post(array $fields): array
    {
        $this->client->request('POST', '/api/investment-transactions', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($fields + [
            'accountId' => $this->accountId,
            'instrumentId' => $this->instrumentId,
            'type' => 'buy',
            'occurredAt' => '2026-01-01T00:00:00+00:00',
        ]));

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
