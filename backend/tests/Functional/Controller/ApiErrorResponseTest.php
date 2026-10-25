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
 * The app shows API errors in the user's language from codes, never from the
 * English `message`: every error the user can meet carries a `code` (with
 * `params`), every validation error a `code` per field.
 */
class ApiErrorResponseTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->user = static::createUser();
        static::authenticate($this->client, $this->user);
    }

    public function testNotFoundCarriesItsCode(): void
    {
        $data = $this->request('GET', '/api/accounts/00000000-0000-7000-8000-000000000000');

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame('account_not_found', $data['code']);
        $this->assertSame([], $data['params']);
        $this->assertSame('Account not found.', $data['message']);
    }

    public function testServiceRefusalCarriesItsCode(): void
    {
        $data = $this->request('PUT', '/api/auth/me/password', ['currentPassword' => 'not the password', 'newPassword' => 'a new password']);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('current_password_incorrect', $data['code']);
    }

    public function testConflictCarriesItsCodeAndParams(): void
    {
        $checking = $this->account(AccountType::Asset, 'Checking');
        $groceries = $this->account(AccountType::Expense, 'Groceries');
        $transaction = new Transaction(
            user: $this->user,
            sourceAccount: $checking,
            destinationAccount: $groceries,
            occurredAt: new \DateTimeImmutable('2026-09-01 10:00'),
            amount: '30.0000',
            currency: 'EUR',
        );
        $transaction->setDescription('Shopping');
        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        $data = $this->request('DELETE', '/api/accounts/'.$checking->getId()->toRfc4122());

        $this->assertResponseStatusCodeSame(409);
        $this->assertSame('account_in_use', $data['code']);
        $this->assertSame(['transactions' => 1, 'investmentTransactions' => 0], $data['params']);
    }

    public function testValidationErrorsCarryAFieldAndACodeEach(): void
    {
        $data = $this->request('POST', '/api/transactions', [
            'description' => '  ',
            'sourceAccountId' => null,
            'destinationAccountId' => null,
            'occurredAt' => '2026-09-01T10:00:00',
            'amount' => '-5',
            'foreignCurrency' => 'EURO',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $violations = array_column($data['violations'], null, 'propertyPath');
        $this->assertSame('required', $violations['description']['code']);
        $this->assertSame('must_be_positive', $violations['amount']['code']);
        $this->assertSame('wrong_length', $violations['foreignCurrency']['code']);
        $this->assertSame('3', $violations['foreignCurrency']['params']['limit']);
        $this->assertSame('transaction_account_required', $violations['destinationAccountId']['code']);
    }

    public function testLengthViolationSendsItsLimit(): void
    {
        $data = $this->request('PUT', '/api/auth/me/password', ['currentPassword' => 'whatever', 'newPassword' => 'short']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('newPassword', $data['violations'][0]['propertyPath']);
        $this->assertSame('too_short', $data['violations'][0]['code']);
        $this->assertSame('8', $data['violations'][0]['params']['limit']);
    }

    public function testWrongTypeIsAViolationToo(): void
    {
        $data = $this->request('PUT', '/api/auth/me/password', ['currentPassword' => 'whatever', 'newPassword' => 12345678]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('newPassword', $data['violations'][0]['propertyPath']);
        $this->assertSame('invalid_type', $data['violations'][0]['code']);
    }

    private function account(AccountType $type, string $name): Account
    {
        $account = new Account($this->user, $type, $name);
        if ($type === AccountType::Asset) {
            $eur = $this->entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']);
            if ($eur === null) {
                $eur = new Currency('EUR', 'Euro', '€', 2);
                $this->entityManager->persist($eur);
            }
            $account->setAssetKind(AssetAccountKind::Checking);
            $account->setCurrency($eur);
        }
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    private function request(string $method, string $uri, ?array $json = null): mixed
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: $json !== null ? json_encode($json) : null);

        return json_decode($this->client->getResponse()->getContent(), true);
    }
}
