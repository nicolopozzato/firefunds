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

class TransactionDeleteTest extends WebTestCaseBase
{
    public function testOwnerDeletesTransactionAndItDisappearsFromTheApi(): void
    {
        $client = static::createClient();
        $owner = static::createUser();
        $id = $this->createTransaction($owner);
        static::authenticate($client, $owner);

        $client->request('GET', '/api/transactions/'.$id);
        $this->assertResponseIsSuccessful();

        $client->request('DELETE', '/api/transactions/'.$id);
        $this->assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/transactions/'.$id);
        $this->assertResponseStatusCodeSame(404);

        $client->request('GET', '/api/transactions?kind=all');
        $this->assertSame(0, json_decode($client->getResponse()->getContent(), true)['total']);

        $client->request('GET', '/api/transactions/'.$id.'/audit-log');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testAnotherUserCanNeitherSeeNorDeleteIt(): void
    {
        $client = static::createClient();
        $id = $this->createTransaction(static::createUser());
        static::authenticate($client, static::createUser());

        $client->request('GET', '/api/transactions/'.$id);
        $this->assertResponseStatusCodeSame(404);

        $client->request('DELETE', '/api/transactions/'.$id);
        $this->assertResponseStatusCodeSame(404);

        $deletedAt = static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT deleted_at FROM transaction WHERE id = ?', [$id]);
        $this->assertNull($deletedAt);
    }

    public function testDeleteRequiresAuthentication(): void
    {
        $client = static::createClient();
        $id = $this->createTransaction(static::createUser());

        $client->request('DELETE', '/api/transactions/'.$id);
        $this->assertResponseStatusCodeSame(401);
    }

    private function createTransaction(User $user): string
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $currency = $entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']);
        if ($currency === null) {
            $currency = new Currency('EUR', 'Euro', '€', 2);
            $entityManager->persist($currency);
        }

        $checking = new Account($user, AccountType::Asset, 'Checking');
        $checking->setAssetKind(AssetAccountKind::Checking);
        $checking->setCurrency($currency);
        $entityManager->persist($checking);

        $expense = new Account($user, AccountType::Expense, 'Groceries');
        $entityManager->persist($expense);

        $transaction = new Transaction(
            user: $user,
            sourceAccount: $checking,
            destinationAccount: $expense,
            occurredAt: new \DateTimeImmutable('2026-09-20T10:00:00+00:00'),
            amount: '25.0000',
            currency: 'EUR',
        );
        $transaction->setDescription('Spesa');
        $entityManager->persist($transaction);
        $entityManager->flush();

        return $transaction->getId()->toRfc4122();
    }
}
