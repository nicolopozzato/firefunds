<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\Category;
use App\Entity\Currency;
use App\Entity\Transaction;
use App\Entity\User;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A list's `from` / `to` dates are calendar days, and a transaction is on the
 * day written where it happened (App\Entity\OccursLocally): a payment on 1
 * October at 00:00 in Rome belongs to October, even for a user who has since
 * moved to New York.
 */
class TransactionListUserDayTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private User $user;
    private Account $checking;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $user = $this->user = static::createUser();
        $user->setTimezone('Europe/Rome');
        static::authenticate($this->client, $user);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $eur = $entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']);
        if ($eur === null) {
            $eur = new Currency('EUR', 'Euro', '€', 2);
            $entityManager->persist($eur);
        }

        $this->checking = new Account($user, AccountType::Asset, 'Checking');
        $this->checking->setAssetKind(AssetAccountKind::Checking);
        $this->checking->setCurrency($eur);
        $entityManager->persist($this->checking);
        $internet = new Account($user, AccountType::Expense, 'Internet provider');
        $entityManager->persist($internet);
        $category = new Category($user, 'Bills');
        $entityManager->persist($category);

        $payment = new Transaction(
            user: $user,
            sourceAccount: $this->checking,
            destinationAccount: $internet,
            occurredAt: new \DateTimeImmutable('2026-10-01 00:00:00', new \DateTimeZone('Europe/Rome')),
            amount: '29.9000',
            currency: 'EUR',
        );
        $payment->setDescription('Fibre');
        $payment->setCategory($category);
        $entityManager->persist($payment);
        $entityManager->flush();
    }

    public function testMidnightInRomeIsInTheNextMonth(): void
    {
        $this->assertSame([], $this->listed('?from=2026-09-01&to=2026-09-30'));
        $this->assertSame(['Fibre'], $this->listed('?from=2026-10-01&to=2026-10-31'));
    }

    /** In New York it was still 30 September: the day stays the written one. */
    public function testTheDayDoesNotMoveWithTheUsersTimezone(): void
    {
        $this->user->setTimezone('America/New_York');
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->assertSame([], $this->listed('?from=2026-09-01&to=2026-09-30'));
        $this->assertSame(['Fibre'], $this->listed('?from=2026-10-01&to=2026-10-31'));
    }

    public function testItIsSentAsWrittenWithItsTimezone(): void
    {
        $this->client->request('GET', '/api/transactions?from=2026-10-01&to=2026-10-31');
        $item = json_decode((string) $this->client->getResponse()->getContent(), true)['items'][0];

        $this->assertSame('2026-10-01T00:00:00', $item['occurredAt']);
        $this->assertSame('Europe/Rome', $item['timezone']);
    }

    public function testTotalsAndDistributionsFollowTheSameDays(): void
    {
        $this->client->request('GET', '/api/transactions/totals?from=2026-09-01&to=2026-09-30');
        $this->assertResponseIsSuccessful();
        $september = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->client->request('GET', '/api/transactions/totals?from=2026-10-01&to=2026-10-31');
        $october = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertNotEquals($september, $october);

        $september = $this->accountDistributions('?from=2026-09-01&to=2026-09-30');
        $this->assertSame([], $september['expenses']);
        $october = $this->accountDistributions('?from=2026-10-01&to=2026-10-31');
        $this->assertSame([['Bills', '29.9000']], array_map(static fn ($row) => [$row['name'], $row['total']], $october['expenses']));
    }

    public function testAnImpossibleDateIsA400(): void
    {
        $this->client->request('GET', '/api/transactions?from=2026-02-30&to=2026-03-31');
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('invalid_date_range', json_decode((string) $this->client->getResponse()->getContent(), true)['code']);
    }

    /** @return string[] descriptions */
    private function listed(string $query): array
    {
        $this->client->request('GET', '/api/transactions'.$query);
        $this->assertResponseIsSuccessful();
        $items = json_decode((string) $this->client->getResponse()->getContent(), true)['items'];

        return array_map(static fn (array $transaction) => $transaction['description'], $items);
    }

    /** @return array<string, mixed> */
    private function accountDistributions(string $query): array
    {
        $this->client->request('GET', '/api/accounts/'.$this->checking->getId()->toRfc4122().'/distributions'.$query);
        $this->assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
