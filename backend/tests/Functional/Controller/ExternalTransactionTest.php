<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\Category;
use App\Entity\Currency;
use App\Entity\User;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A money transaction can have an "external" side (null account): money
 * leaving the tracked accounts without an expense account (e.g. 20 € handed
 * to a friend for a gift, categorised "Gifts"), or arriving from outside them
 * without a revenue account.
 */
class ExternalTransactionTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $user;
    private Account $checking;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->user = static::createUser();
        static::authenticate($this->client, $this->user);

        $eur = $this->entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']);
        if ($eur === null) {
            $eur = new Currency('EUR', 'Euro', '€', 2, true);
            $this->entityManager->persist($eur);
        }

        $this->checking = new Account($this->user, AccountType::Asset, 'Banca Uno');
        $this->checking->setAssetKind(AssetAccountKind::Checking);
        $this->checking->setCurrency($eur);
        $this->checking->setOpeningBalance('100.0000');
        $this->entityManager->persist($this->checking);
        $this->entityManager->flush();
    }

    public function testWithdrawalToExternalCountsAsAnExpense(): void
    {
        $gifts = new Category($this->user, 'Gifts');
        $this->entityManager->persist($gifts);
        $this->entityManager->flush();

        $created = $this->request('POST', '/api/transactions', $this->payload(
            source: $this->checking->getId()->toRfc4122(),
            destination: null,
            amount: '20',
            categoryId: $gifts->getId()->toRfc4122(),
        ));
        $this->assertResponseStatusCodeSame(201);
        $this->assertNull($created['destinationAccount']);
        $this->assertSame('EUR', $created['currency']);

        $withdrawals = $this->request('GET', '/api/transactions?kind=withdrawal&from=2026-09-01&to=2026-09-30');
        $this->assertSame([$created['id']], array_column($withdrawals['items'], 'id'));

        $distributions = $this->request('GET', '/api/transactions/distributions?kind=withdrawal&from=2026-09-01&to=2026-09-30');
        $this->assertSame([['id' => $gifts->getId()->toRfc4122(), 'name' => 'Gifts', 'total' => '20.0000']], $distributions['categories']);
        $this->assertSame([['id' => null, 'name' => null, 'total' => '20.0000']], $distributions['destinationAccounts']);

        $expenses = $this->request('GET', '/api/accounts/'.$this->checking->getId()->toRfc4122().'/distributions?from=2026-09-01&to=2026-09-30');
        $this->assertSame('20.0000', $expenses['expenses'][0]['total']);

        $this->assertSame('80.0000', $this->request('GET', '/api/accounts/'.$this->checking->getId()->toRfc4122())['currentBalance']);
    }

    public function testDepositFromExternalCountsAsIncome(): void
    {
        $created = $this->request('POST', '/api/transactions', $this->payload(
            source: null,
            destination: $this->checking->getId()->toRfc4122(),
            amount: '50',
        ));
        $this->assertResponseStatusCodeSame(201);
        $this->assertNull($created['sourceAccount']);

        $deposits = $this->request('GET', '/api/transactions?kind=deposit&from=2026-09-01&to=2026-09-30');
        $this->assertSame([$created['id']], array_column($deposits['items'], 'id'));
        $this->assertSame('150.0000', $this->request('GET', '/api/accounts/'.$this->checking->getId()->toRfc4122())['currentBalance']);
    }

    public function testExternalOnlyPairsWithAnAssetAccount(): void
    {
        $groceries = new Account($this->user, AccountType::Expense, 'Groceries');
        $salary = new Account($this->user, AccountType::Revenue, 'Salary');
        $this->entityManager->persist($groceries);
        $this->entityManager->persist($salary);
        $this->entityManager->flush();

        $this->request('POST', '/api/transactions', $this->payload(null, null, '10'));
        $this->assertResponseStatusCodeSame(422);

        $this->request('POST', '/api/transactions', $this->payload(null, $groceries->getId()->toRfc4122(), '10'));
        $this->assertResponseStatusCodeSame(400);

        $this->request('POST', '/api/transactions', $this->payload($salary->getId()->toRfc4122(), null, '10'));
        $this->assertResponseStatusCodeSame(400);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?string $source, ?string $destination, string $amount, ?string $categoryId = null): array
    {
        return [
            'description' => 'Regalo compleanno',
            'sourceAccountId' => $source,
            'destinationAccountId' => $destination,
            'occurredAt' => '2026-09-20T10:00:00+02:00',
            'amount' => $amount,
            'categoryId' => $categoryId,
        ];
    }

    /**
     * @param array<string, mixed>|null $json
     * @return mixed decoded JSON body
     */
    private function request(string $method, string $uri, ?array $json = null): mixed
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: $json !== null ? json_encode($json) : null);
        $content = $this->client->getResponse()->getContent();

        return $content !== '' ? json_decode($content, true) : null;
    }
}
