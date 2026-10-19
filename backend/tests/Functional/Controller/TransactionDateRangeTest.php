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

/**
 * The "All" timeframe: from the first thing the user has (a transaction, a
 * trade or an account's opening balance) up to today.
 */
class TransactionDateRangeTest extends WebTestCaseBase
{
    public function testNothingYetStillEndsToday(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('GET', '/api/transactions/date-range');

        $this->assertSame(['earliest' => null, 'latest' => $this->today()], json_decode($client->getResponse()->getContent(), true));
    }

    public function testTradesAndOpeningBalancesCountAndItRunsToToday(): void
    {
        $client = static::createClient();
        $user = static::createUser();
        static::authenticate($client, $user);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $broker = new Account($user, AccountType::Asset, 'Broker');
        $broker->setAssetKind(AssetAccountKind::Investment);
        $broker->setOpeningBalanceDate(new \DateTimeImmutable('2019-05-01'));
        $entityManager->persist($broker);
        $instrument = new InvestmentInstrument($user, 'ETF', InvestmentInstrumentType::Etf);
        $entityManager->persist($instrument);
        $entityManager->persist(new InvestmentTransaction($user, $broker, $instrument, InvestmentTransactionType::Buy, '1', '100', new \DateTimeImmutable('2020-03-02 10:00', new \DateTimeZone('Europe/Rome'))));
        $entityManager->persist(new Transaction($user, null, $broker, new \DateTimeImmutable('2024-01-10 09:00', new \DateTimeZone('Europe/Rome')), '50.0000', 'EUR'));
        $entityManager->flush();

        $client->request('GET', '/api/transactions/date-range');

        $this->assertSame(['earliest' => '2019-05-01', 'latest' => $this->today()], json_decode($client->getResponse()->getContent(), true));
    }

    private function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Rome')))->format('Y-m-d');
    }
}
