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
 * An account's currency can be changed only while nothing has been recorded
 * on it: afterwards every amount would silently change meaning.
 */
class AccountCurrencyLockTest extends WebTestCaseBase
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

        foreach ([['EUR', 'Euro', '€'], ['USD', 'US Dollar', '$']] as [$code, $name, $symbol]) {
            $currency = $this->entityManager->getRepository(Currency::class)->findOneBy(['code' => $code]);
            if ($currency === null) {
                $this->entityManager->persist(new Currency($code, $name, $symbol, 2));
            }
        }
        $this->entityManager->flush();

        $this->checking = new Account($this->user, AccountType::Asset, 'Banca Uno');
        $this->checking->setAssetKind(AssetAccountKind::Checking);
        $this->checking->setCurrency($this->entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']));
        $this->entityManager->persist($this->checking);
        $this->entityManager->flush();
    }

    public function testCurrencyCanChangeWhileTheAccountHasNoTransactions(): void
    {
        $updated = $this->update('USD');

        $this->assertResponseIsSuccessful();
        $this->assertSame('USD', $updated['currencyCode']);
    }

    public function testCurrencyIsLockedOnceTheAccountHasTransactions(): void
    {
        $groceries = new Account($this->user, AccountType::Expense, 'Supermercato');
        $this->entityManager->persist($groceries);
        $this->entityManager->persist(new Transaction(
            user: $this->user,
            sourceAccount: $this->checking,
            destinationAccount: $groceries,
            occurredAt: new \DateTimeImmutable('2026-09-01T10:00:00+00:00'),
            amount: '10.0000',
            currency: 'EUR',
        ));
        $this->entityManager->flush();

        $this->update('USD');
        $this->assertResponseStatusCodeSame(400);

        // Saving the rest of the form with the currency unchanged still works.
        $updated = $this->update('EUR', name: 'Banca Uno Spa');
        $this->assertResponseIsSuccessful();
        $this->assertSame('Banca Uno Spa', $updated['name']);
    }

    /**
     * @return array<string, mixed>
     */
    private function update(string $currencyCode, string $name = 'Banca Uno'): array
    {
        $this->client->jsonRequest('PUT', '/api/accounts/'.$this->checking->getId()->toRfc4122(), [
            'name' => $name,
            'currencyCode' => $currencyCode,
            'openingBalance' => '0',
        ]);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
