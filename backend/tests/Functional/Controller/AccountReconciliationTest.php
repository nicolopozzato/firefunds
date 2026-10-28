<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\Currency;
use App\Entity\Transaction;
use App\Entity\User;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Checking an account against the bank's balance: marked when it matches,
 * refused when it doesn't, unless the difference is recorded as an expense or
 * an income of that day.
 */
class AccountReconciliationTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private User $user;
    private Account $checking;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = static::createUser();
        static::authenticate($this->client, $this->user);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $currency = $entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']);
        if ($currency === null) {
            $currency = new Currency('EUR', 'Euro', '€', 2);
            $entityManager->persist($currency);
        }

        $this->checking = new Account($this->user, AccountType::Asset, 'Checking');
        $this->checking->setAssetKind(AssetAccountKind::Checking);
        $this->checking->setCurrency($currency);
        $this->checking->setOpeningBalance('1000.0000');
        $this->checking->setOpeningBalanceDate(new \DateTimeImmutable('2026-09-01'));
        $shop = new Account($this->user, AccountType::Expense, 'Shop');
        $entityManager->persist($this->checking);
        $entityManager->persist($shop);
        $entityManager->persist(new Transaction($this->user, $this->checking, $shop, new \DateTimeImmutable('2026-09-10T12:00:00'), '200.0000', 'EUR'));
        $entityManager->flush();
    }

    public function testAMatchingBalanceMarksTheAccountAsChecked(): void
    {
        $result = $this->reconcile(['day' => '2026-09-30', 'balance' => '800']);

        $this->assertResponseIsSuccessful();
        $this->assertSame('0.0000', $result['difference']);
        $this->assertNull($result['transactionId']);
        $this->assertSame('2026-09-30', $result['account']['reconciledOn']);
        $this->assertSame('800.0000', $result['account']['reconciledBalance']);
    }

    public function testADifferenceIsRefusedUntilItIsSettled(): void
    {
        $this->reconcile(['day' => '2026-09-30', 'balance' => '750']);
        $this->assertResponseStatusCodeSame(409);
        $this->assertSame('reconciliation_difference', json_decode((string) $this->client->getResponse()->getContent(), true)['code']);

        $this->reconcile(['day' => '2026-09-30', 'balance' => '750', 'settle' => true]);
        $this->assertResponseStatusCodeSame(422, 'settling needs a description');
    }

    public function testLessInTheBankIsRecordedAsAnExpenseOfThatDay(): void
    {
        $result = $this->reconcile(['day' => '2026-09-30', 'balance' => '750', 'settle' => true, 'description' => 'Contanti']);

        $this->assertResponseIsSuccessful();
        $this->assertSame('-50.0000', $result['difference']);
        $this->assertSame('750.0000', $result['account']['currentBalance']);

        $this->client->request('GET', '/api/transactions/'.$result['transactionId']);
        $transaction = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('50.0000', $transaction['amount']);
        $this->assertSame((string) $this->checking->getId(), $transaction['sourceAccount']['id']);
        $this->assertNull($transaction['destinationAccount']);
        $this->assertStringStartsWith('2026-09-30', $transaction['occurredAt']);
    }

    public function testMoreInTheBankIsRecordedAsAnIncome(): void
    {
        $result = $this->reconcile(['day' => '2026-09-30', 'balance' => '810.5', 'settle' => true, 'description' => 'Interessi']);

        $this->assertSame('10.5000', $result['difference']);
        $this->client->request('GET', '/api/transactions/'.$result['transactionId']);
        $transaction = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertNull($transaction['sourceAccount']);
        $this->assertSame((string) $this->checking->getId(), $transaction['destinationAccount']['id']);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function reconcile(array $body): array
    {
        $this->client->request('POST', "/api/accounts/{$this->checking->getId()}/reconcile", server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($body));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
