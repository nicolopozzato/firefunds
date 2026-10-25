<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\Currency;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A transfer between accounts in two currencies carries what left the source
 * and what arrived on the destination (the rate is the two): each account
 * moves by its own amount, and the second one is asked for, never guessed.
 */
class TransferBetweenCurrenciesTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Account $euroChecking;
    private Account $euroSavings;
    private Account $dollarAccount;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = static::createUser();
        static::authenticate($this->client, $user);

        $this->euroChecking = $this->account($user, 'Conto', 'EUR');
        $this->euroSavings = $this->account($user, 'Risparmio', 'EUR');
        $this->dollarAccount = $this->account($user, 'Conto USA', 'USD');
        $this->entityManager->flush();
    }

    public function testTransferBetweenDifferentCurrenciesNeedsTheAmountThatArrived(): void
    {
        $response = $this->transfer($this->euroChecking, $this->dollarAccount);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('destination_amount_required', $response['code']);
        $this->assertSame(['source' => 'EUR', 'destination' => 'USD'], $response['params']);
    }

    public function testEachAccountMovesByItsOwnAmount(): void
    {
        $created = $this->transfer($this->euroChecking, $this->dollarAccount, destinationAmount: '110');

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('100', $created['amount']);
        $this->assertSame('EUR', $created['currency']);
        $this->assertSame('110.0000', $created['destinationAmount']);
        $this->assertSame('USD', $created['destinationCurrency']);
        $this->assertSame('400.0000', $this->request('GET', '/api/accounts/'.$this->euroChecking->getId())['currentBalance']);
        $this->assertSame('610.0000', $this->request('GET', '/api/accounts/'.$this->dollarAccount->getId())['currentBalance']);

        $history = $this->request('GET', '/api/accounts/'.$this->dollarAccount->getId().'/balance-history?from=2026-09-19&to=2026-09-20');
        $this->assertSame(['500.0000', '610.0000'], array_column($history, 'balance'), 'what arrived, from the day it did');
    }

    public function testTransferInTheSameCurrencyHasNoSecondAmount(): void
    {
        $created = $this->transfer($this->euroChecking, $this->euroSavings, destinationAmount: '99');

        $this->assertResponseStatusCodeSame(201);
        $this->assertNull($created['destinationAmount']);
        $this->assertSame('600.0000', $this->request('GET', '/api/accounts/'.$this->euroSavings->getId())['currentBalance']);
    }

    public function testATransferIgnoresAnOriginalAmount(): void
    {
        $payload = $this->payload($this->euroChecking, $this->euroSavings) + ['foreignAmount' => '110', 'foreignCurrency' => 'USD'];
        $created = $this->request('POST', '/api/transactions', $payload);

        $this->assertResponseStatusCodeSame(201);
        $this->assertNull($created['foreignAmount']);
        $this->assertNull($created['foreignCurrency']);
    }

    public function testEditingATransferKeepsTheSecondAmountInStep(): void
    {
        $created = $this->transfer($this->euroChecking, $this->euroSavings);

        $refused = $this->request('PUT', '/api/transactions/'.$created['id'], $this->payload($this->euroChecking, $this->dollarAccount));
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('destination_amount_required', $refused['code']);

        $edited = $this->request('PUT', '/api/transactions/'.$created['id'], $this->payload($this->euroChecking, $this->dollarAccount, '108.50'));
        $this->assertResponseIsSuccessful();
        $this->assertSame('108.5000', $edited['destinationAmount']);

        $back = $this->request('PUT', '/api/transactions/'.$created['id'], $this->payload($this->euroChecking, $this->euroSavings, '108.50'));
        $this->assertNull($back['destinationAmount']);
    }

    private function account(\App\Entity\User $user, string $name, string $currencyCode): Account
    {
        $currency = $this->entityManager->getRepository(Currency::class)->findOneBy(['code' => $currencyCode]);
        $account = new Account($user, AccountType::Asset, $name);
        $account->setAssetKind(AssetAccountKind::Checking);
        $account->setCurrency($currency);
        $account->setOpeningBalance('500.0000');
        $this->entityManager->persist($account);

        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    private function transfer(Account $source, Account $destination, ?string $destinationAmount = null): array
    {
        return $this->request('POST', '/api/transactions', $this->payload($source, $destination, $destinationAmount));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Account $source, Account $destination, ?string $destinationAmount = null): array
    {
        return [
            'description' => 'Giroconto',
            'sourceAccountId' => $source->getId()->toRfc4122(),
            'destinationAccountId' => $destination->getId()->toRfc4122(),
            'occurredAt' => '2026-09-20T10:00:00+02:00',
            'amount' => '100',
            'destinationAmount' => $destinationAmount,
        ];
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $uri, ?array $json = null): array
    {
        $this->client->jsonRequest($method, $uri, $json ?? []);

        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }
}
