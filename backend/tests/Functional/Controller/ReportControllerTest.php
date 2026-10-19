<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Entity\User;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;

/** GET /api/reports/income-expenses and /api/reports/net-worth (IncomeExpenseReportService, NetWorthReportService). */
class ReportControllerTest extends WebTestCaseBase
{
    public function testReturnsTheIncomeAndExpensesReport(): void
    {
        $client = static::createClient();
        $user = static::createUser();
        $checking = $this->account($user, 'Checking', AccountType::Asset, '250.0000');
        $shop = $this->account($user, 'Shop', AccountType::Expense);
        $this->transaction($user, $checking, $shop, '40.0000', '2026-09-10 10:00', $this->category($user, 'Food'));
        static::authenticate($client, $user);

        $client->request('GET', '/api/reports/income-expenses?from=2026-08-01&to=2026-09-30&by=month&compare=lastYear');

        $this->assertResponseIsSuccessful();
        $report = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame(['2026-08', '2026-09'], array_column($report['periods'], 'key'));
        $this->assertSame('40.0000', $report['summary']['expenses']);
        $this->assertSame([['Food', ['0.0000', '40.0000']]], array_map(static fn ($row) => [$row['name'], $row['values']], $report['expenses']['rows']));
        $this->assertSame(['2025-08-01', '2025-09-30'], [$report['comparison']['from'], $report['comparison']['to']]);
        $this->assertSame(['Checking'], array_column($report['accounts'], 'name'));
        $this->assertCount(61, $report['netWorth']);
    }

    /** Without `compare` there is nothing to compare with; `by` defaults to months. */
    public function testDefaults(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('GET', '/api/reports/income-expenses?from=2026-01-01&to=2026-12-31');

        $this->assertResponseIsSuccessful();
        $report = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('month', $report['by']);
        $this->assertCount(12, $report['periods']);
        $this->assertNull($report['comparison']);
    }

    public function testInvalidOptionsAreRejected(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('GET', '/api/reports/income-expenses?from=2026-09-30&to=2026-09-01');
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('date_range_inverted', json_decode((string) $client->getResponse()->getContent(), true)['code']);

        $client->request('GET', '/api/reports/income-expenses?from=2026-09-01');
        $this->assertResponseStatusCodeSame(400);

        $client->request('GET', '/api/reports/income-expenses?from=2026-02-30&to=2026-03-01');
        $this->assertResponseStatusCodeSame(400);

        $client->request('GET', '/api/reports/income-expenses?from=2026-01-01&to=2026-03-01&by=week');
        $this->assertResponseStatusCodeSame(400);
    }

    public function testReturnsTheNetWorthReport(): void
    {
        $client = static::createClient();
        $user = static::createUser();
        $this->account($user, 'Checking', AccountType::Asset, '250.0000');
        static::authenticate($client, $user);

        $client->request('GET', '/api/reports/net-worth?year=2025');

        $this->assertResponseIsSuccessful();
        $report = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame(2025, $report['year']);
        $this->assertSame(['2025-01', '2025-01-31'], [$report['months'][0]['month'], $report['months'][0]['date']]);
        $this->assertSame(['Checking'], array_column($report['cash'], 'name'));
        $this->assertSame(array_fill(0, 12, '250.0000'), $report['total']['values']);
        $this->assertSame('2024-12', $report['previousMonths'][11]['month']);

        $client->request('GET', '/api/reports/net-worth');
        $this->assertResponseIsSuccessful();
        $this->assertSame((int) date('Y'), json_decode((string) $client->getResponse()->getContent(), true)['year']);

        $client->request('GET', '/api/reports/net-worth?year=abc');
        $this->assertResponseStatusCodeSame(400);
    }

    public function testRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/reports/income-expenses?from=2026-01-01&to=2026-12-31');

        $this->assertResponseStatusCodeSame(401);
    }

    /** A report's cell opens the transactions of one category in one column: the list narrowed to it. */
    public function testTransactionListNarrowedToACategory(): void
    {
        $client = static::createClient();
        $user = static::createUser();
        $checking = $this->account($user, 'Checking', AccountType::Asset);
        $shop = $this->account($user, 'Shop', AccountType::Expense);
        $food = $this->category($user, 'Food');
        $this->transaction($user, $checking, $shop, '40.0000', '2026-09-10 10:00', $food, 'Groceries');
        $this->transaction($user, $checking, $shop, '15.0000', '2026-09-11 10:00', null, 'Something');
        $this->transaction($user, $checking, $shop, '25.0000', '2026-10-11 10:00', $food, 'October groceries');
        static::authenticate($client, $user);

        $descriptions = function (string $query) use ($client): array {
            $client->request('GET', '/api/transactions?'.$query);
            $this->assertResponseIsSuccessful();

            return array_column(json_decode((string) $client->getResponse()->getContent(), true)['items'], 'description');
        };

        $this->assertSame(['Groceries'], $descriptions('kind=withdrawal&from=2026-09-01&to=2026-09-30&categoryId='.$food->getId()));
        $this->assertSame(['Something'], $descriptions('kind=withdrawal&from=2026-09-01&to=2026-09-30&categoryId=none'));

        $client->request('GET', '/api/transactions?kind=all&categoryId='.$food->getId());
        $this->assertResponseStatusCodeSame(400);

        $client->request('GET', '/api/transactions?kind=withdrawal&categoryId='.$this->category(static::createUser(), 'Not mine')->getId());
        $this->assertResponseStatusCodeSame(404);
    }

    private function account(User $user, string $name, AccountType $type, string $openingBalance = '0.0000'): Account
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $account = new Account($user, $type, $name);
        if ($type === AccountType::Asset) {
            $account->setAssetKind(AssetAccountKind::Checking);
            $account->setOpeningBalance($openingBalance);
        }
        $entityManager->persist($account);
        $entityManager->flush();

        return $account;
    }

    private function category(User $user, string $name): Category
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $category = new Category($user, $name);
        $entityManager->persist($category);
        $entityManager->flush();

        return $category;
    }

    private function transaction(User $user, Account $source, Account $destination, string $amount, string $occurredAt, ?Category $category, string $description = 'Payment'): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $transaction = new Transaction($user, $source, $destination, new \DateTimeImmutable($occurredAt, new \DateTimeZone('UTC')), $amount, 'EUR');
        $transaction->setDescription($description);
        $transaction->setCategory($category);
        $entityManager->persist($transaction);
        $entityManager->flush();
    }
}
