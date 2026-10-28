<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\Currency;
use App\Entity\ExchangeRateSource;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentInstrumentType;
use App\Entity\User;
use App\Repository\ExchangeRateRepository;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The trade's amount (what the broker charged) over HTTP, as the form sends it.
 */
class InvestmentTransactionAmountTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private User $user;
    private string $accountId;
    private string $instrumentId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $user = $this->user = static::createUser();
        static::authenticate($this->client, $user);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $currency = $entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']);
        if ($currency === null) {
            $currency = new Currency('EUR', 'Euro', '€', 2);
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

    public function testAmountFarFromQuantityTimesPriceIsAccepted(): void
    {
        $response = $this->post(['quantity' => '0.12', 'unitPrice' => '100', 'amount' => '120']);

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('120.0000', $response['amount']);
    }

    public function testAnInstrumentInAnotherCurrencyIsConvertedAtTheDaysRateWhenTheAmountIsLeftOut(): void
    {
        $dollars = $this->dollarInstrument();
        static::getContainer()->get(ExchangeRateRepository::class)->upsert('USD', ['2026-01-01' => ['1.25000000', ExchangeRateSource::Ecb]]);

        $response = $this->post(['quantity' => '2', 'unitPrice' => '100', 'instrumentId' => $dollars]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('160.0000', $response['amount'], '200 USD at 1 EUR = 1.25 USD');
        $this->assertSame('EUR', $response['currency']);
        $this->assertSame('USD', $response['unitPriceCurrency']);
        $this->assertSame('100', $response['unitPrice']);
    }

    public function testWithoutARateTheAmountMustBeGiven(): void
    {
        $dollars = $this->dollarInstrument();

        $response = $this->post(['quantity' => '2', 'unitPrice' => '100', 'instrumentId' => $dollars]);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('amount_required_other_currency', $response['code']);
        $this->assertSame(['instrument' => 'USD', 'account' => 'EUR'], $response['params']);

        $response = $this->post(['quantity' => '2', 'unitPrice' => '100', 'instrumentId' => $dollars, 'amount' => '158.20']);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('158.2000', $response['amount']);
    }

    public function testATradeDeletedMomentsAgoCanBeRestored(): void
    {
        $id = $this->post(['quantity' => '1', 'unitPrice' => '100'])['id'];

        $this->client->request('DELETE', '/api/investment-transactions/'.$id);
        $this->client->request('POST', '/api/investment-transactions/'.$id.'/restore');
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/api/investment-transactions/'.$id);
        $this->assertResponseIsSuccessful();

        $this->client->request('POST', '/api/investment-transactions/'.$id.'/restore');
        $this->assertResponseStatusCodeSame(409, 'not deleted: nothing to restore');
    }

    public function testDescriptionSuggestionsOfferTheLatestTradeOfEachDescription(): void
    {
        $this->post(['quantity' => '1', 'unitPrice' => '100', 'description' => 'PAC mensile', 'fees' => '1.5', 'occurredAt' => '2026-01-01T09:00:00+00:00']);
        $this->post(['quantity' => '2', 'unitPrice' => '90', 'description' => 'PAC mensile', 'fees' => '1.5', 'occurredAt' => '2026-02-01T09:00:00+00:00']);

        $this->client->request('GET', '/api/investment-transactions/suggestions?q=pac');
        $suggestions = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertCount(1, $suggestions, 'one per description');
        $this->assertStringStartsWith('2026-02-01', $suggestions[0]['occurredAt']);
        $this->assertSame($this->instrumentId, $suggestions[0]['instrumentId']);
    }

    private function dollarInstrument(): string
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $instrument = new InvestmentInstrument($this->user, 'S&P 500', InvestmentInstrumentType::Etf);
        $instrument->setCurrency('USD');
        $entityManager->persist($instrument);
        $entityManager->flush();

        return (string) $instrument->getId();
    }

    /**
     * @param array<string, string|int|float> $fields
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
