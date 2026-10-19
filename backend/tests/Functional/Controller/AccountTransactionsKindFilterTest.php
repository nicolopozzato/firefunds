<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentInstrumentType;
use App\Entity\InvestmentTransaction;
use App\Entity\InvestmentTransactionType;
use App\Entity\Transaction;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * GET /api/transactions?accountId=…&kind=trade|cash : the "all / trades / cash"
 * filter of an investment account's transactions.
 */
class AccountTransactionsKindFilterTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private string $brokerId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $user = static::createUser();
        static::authenticate($this->client, $user);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $bank = new Account($user, AccountType::Asset, 'Banca');
        $bank->setAssetKind(AssetAccountKind::Checking);
        $broker = new Account($user, AccountType::Asset, 'Broker');
        $broker->setAssetKind(AssetAccountKind::Investment);
        $taxes = new Account($user, AccountType::Expense, 'Imposte');
        $instrument = new InvestmentInstrument($user, 'Vanguard FTSE All-World', InvestmentInstrumentType::Etf);
        $instrument->setTicker('VWCE');
        foreach ([$bank, $broker, $taxes, $instrument] as $entity) {
            $entityManager->persist($entity);
        }

        $deposit = new Transaction($user, $bank, $broker, new \DateTimeImmutable('2026-01-10 10:00'), '1000.0000', 'EUR');
        $deposit->setDescription('Versamento');
        $stampDuty = new Transaction($user, $broker, $taxes, new \DateTimeImmutable('2026-01-12 10:00'), '6.7000', 'EUR');
        $stampDuty->setDescription('Bollo');
        $buy = new InvestmentTransaction($user, $broker, $instrument, InvestmentTransactionType::Buy, '3', '150.0000', new \DateTimeImmutable('2026-01-11 10:00'));
        $buy->setDescription('VWCE 3 quote');
        foreach ([$deposit, $stampDuty, $buy] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $this->brokerId = (string) $broker->getId();
    }

    public function testAllMixesTradesAndCash(): void
    {
        $this->assertSame(['Bollo', 'VWCE 3 quote', 'Versamento'], $this->descriptions('all'));
    }

    public function testTradeShowsOnlyBuysAndSells(): void
    {
        $items = $this->list('trade');

        $this->assertSame(['VWCE 3 quote'], array_column($items, 'description'));
        $this->assertSame(1, $this->total('trade'));
    }

    public function testCashShowsOnlyMoneyTransactions(): void
    {
        $this->assertSame(['Bollo', 'Versamento'], $this->descriptions('cash'));
        $this->assertSame(2, $this->total('cash'));
    }

    public function testInstrumentSideIsTheTickerWithItsType(): void
    {
        [$buy] = $this->list('trade');

        $this->assertSame('VWCE', $buy['destinationAccount']['name']);
        $this->assertSame('instrument', $buy['destinationAccount']['type']);
        $this->assertSame('etf', $buy['destinationAccount']['instrumentType']);
    }

    /**
     * @return list<string>
     */
    private function descriptions(string $kind): array
    {
        return array_column($this->list($kind), 'description');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function list(string $kind): array
    {
        return $this->request($kind)['items'];
    }

    private function total(string $kind): int
    {
        return $this->request($kind)['total'];
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $kind): array
    {
        $this->client->request('GET', \sprintf('/api/transactions?accountId=%s&kind=%s&from=2026-01-01&to=2026-12-31', $this->brokerId, $kind));
        $this->assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
