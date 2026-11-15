<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\Category;
use App\Entity\Currency;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentInstrumentType;
use App\Entity\InvestmentPrice;
use App\Entity\InvestmentTransaction;
use App\Entity\InvestmentTransactionType;
use App\Entity\Transaction;
use App\Entity\User;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The cash flow report: today's balance, then what is entered, what recurs
 * and what the past suggests, never counting a thing twice.
 *
 * Today is 2026-11-10. History: 5000 arrived on 2025-12-01; groceries
 * 600 in December 2025 and 300 in each of August, September and October
 * 2026, 50 so far in November; 100 of groceries entered for 2026-11-25; a
 * phone recurrence of 10 on the 20th. Today's balance: 5000 - 600 - 900 - 50 = 3450.
 */
class CashFlowReportTest extends WebTestCaseBase
{
    use ClockSensitiveTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $user;
    private Account $checking;

    protected function setUp(): void
    {
        self::mockTime('2026-11-10 10:00:00 Europe/Rome');
        $this->client = static::createClient();
        $this->user = static::createUser();
        static::authenticate($this->client, $this->user);
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $euro = $this->entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']);
        $this->checking = new Account($this->user, AccountType::Asset, 'Checking');
        $this->checking->setAssetKind(AssetAccountKind::Checking)->setCurrency($euro);
        $salary = new Account($this->user, AccountType::Revenue, 'Employer');
        $shop = new Account($this->user, AccountType::Expense, 'Supermarket');
        $phone = new Account($this->user, AccountType::Expense, 'Phone company');
        $groceries = new Category($this->user, 'Groceries');
        foreach ([$this->checking, $salary, $shop, $phone, $groceries] as $entity) {
            $this->entityManager->persist($entity);
        }

        $this->transaction($salary, $this->checking, '2025-12-01 09:00', '5000');
        foreach (['2025-12-15' => '600', '2026-08-15' => '300', '2026-09-15' => '300', '2026-10-15' => '300', '2026-11-05' => '50', '2026-11-25' => '100'] as $day => $amount) {
            $this->transaction($this->checking, $shop, "{$day} 18:00", $amount)->setCategory($groceries);
        }
        $this->entityManager->flush();

        $this->client->request('POST', '/api/recurring-transactions', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'description' => 'SIM', 'amount' => '10', 'startDate' => '2026-01-20', 'time' => '09:00', 'timezone' => 'Europe/Rome',
            'sourceAccountId' => (string) $this->checking->getId(), 'destinationAccountId' => (string) $phone->getId(),
        ]));
        $this->assertResponseStatusCodeSame(201);
    }

    public function testEveryLayerFromTodaysBalance(): void
    {
        $report = $this->report(['months' => 1, 'by' => 'month', 'average' => 3]);

        $this->assertSame('2026-11-10', $report['today']);
        $this->assertSame('3450.0000', $report['opening']);
        $this->assertSame('3450.0000', $report['openingNetWorth'], 'nothing held');
        $this->assertSame(3, $report['estimateMonths']);
        $this->assertSame([['2026-11-10', '2026-11-30'], ['2026-12-01', '2026-12-31']], array_map(static fn (array $p) => [$p['start'], $p['end']], $report['periods']));

        [$november, $december] = $report['periods'];
        // The mean of 300 expects 150 more in November: 50 happened, 100 is entered.
        $this->assertSame(['entered' => '100.0000', 'recurring' => '10.0000', 'estimated' => '150.0000'], $november['expense']);
        $this->assertSame('3190.0000', $november['balance']);
        $this->assertSame(['entered' => '0.0000', 'recurring' => '10.0000', 'estimated' => '300.0000'], $december['expense']);
        $this->assertSame('2880.0000', $december['balance']);
        $this->assertSame(['entered' => '0.0000', 'recurring' => '0.0000', 'estimated' => '0.0000'], $december['income'], 'the 5000 is older than the mean');

        $this->assertSame([
            ['recurring', 'expense', 'SIM'],
            ['entered', 'expense', 'Supermarket'],
            ['estimated', 'expense', 'Groceries'],
        ], array_map(static fn (array $item) => [$item['source'], $item['flow'], $item['label'] ?? $item['accountName']], $november['items']));

        $this->assertSame([['Checking', '3450.0000', ['3190.0000', '2880.0000']]], array_map(
            static fn (array $account) => [$account['name'], $account['opening'], $account['balances']],
            $report['accounts'],
        ));
    }

    public function testTheSameMonthAYearEarlierWeighsAsAsked(): void
    {
        [$november, $december] = $this->report(['months' => 1, 'average' => 3, 'seasonal' => 50])['periods'];

        // November 2025 is before any history: the mean alone, as without the weight.
        $this->assertSame('150.0000', $november['expense']['estimated']);
        // December: half of 300 and half of 600.
        $this->assertSame('450.0000', $december['expense']['estimated']);
    }

    public function testWhatIsHeldCountsAtTodaysPriceAndAPlanMovesCashIntoIt(): void
    {
        $euro = $this->entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']);
        $broker = new Account($this->user, AccountType::Asset, 'Broker');
        $broker->setAssetKind(AssetAccountKind::Investment)->setCurrency($euro);
        $etf = new InvestmentInstrument($this->user, 'World ETF', InvestmentInstrumentType::Etf);
        foreach ([$broker, $etf, new InvestmentPrice($etf, new \DateTimeImmutable('2026-11-09'), '30.0000')] as $entity) {
            $this->entityManager->persist($entity);
        }
        // 1000 moved to the broker, 10 units bought at 25: 750 of cash and 300 held there.
        $this->transaction($this->checking, $broker, '2026-09-30 09:00', '1000');
        $this->entityManager->persist(new InvestmentTransaction($this->user, $broker, $etf, InvestmentTransactionType::Buy, '10', '25', new \DateTimeImmutable('2026-10-01 10:00'), '250'));
        $this->entityManager->flush();

        // A plan of 100 a month, 1 of fee, on the 5th.
        $this->client->request('POST', '/api/recurring-transactions', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'description' => 'PAC', 'planMode' => 'amount', 'amount' => '100', 'fees' => '1', 'startDate' => '2026-01-05', 'timezone' => 'Europe/Rome',
            'sourceAccountId' => (string) $broker->getId(), 'instrumentId' => (string) $etf->getId(), 'mode' => 'confirm',
        ]));
        $this->assertResponseStatusCodeSame(201);

        $report = $this->report(['months' => 1, 'average' => 3]);
        $this->assertSame('3200.0000', $report['opening']);
        $this->assertSame('3500.0000', $report['openingNetWorth']);
        [$november, $december] = $report['periods'];
        $this->assertSame(['2940.0000', '3240.0000'], [$november['balance'], $november['netWorth']]);
        $this->assertSame('101.0000', $december['invested']['recurring']);
        // The cash pays 101, the net worth loses only the fee.
        $this->assertSame(['2529.0000', '2929.0000'], [$december['balance'], $december['netWorth']]);

        $byName = array_column($report['accounts'], null, 'name');
        $this->assertSame(['750.0000', '1050.0000'], [$byName['Broker']['opening'], $byName['Broker']['openingValue']]);
        $this->assertSame(['649.0000', '1049.0000'], [$byName['Broker']['balances'][1], $byName['Broker']['values'][1]]);
    }

    public function testWhatARecurrenceLooksLikeIsNotEstimatedAgain(): void
    {
        // A phone bill from before the recurrence existed, never linked to it.
        $phone = $this->entityManager->getRepository(Account::class)->findOneBy(['user' => $this->user, 'name' => 'Phone company']);
        $this->transaction($this->checking, $phone, '2026-10-20 09:00', '10');
        $this->entityManager->flush();

        [$november] = $this->report(['months' => 1, 'average' => 3])['periods'];
        $this->assertSame(['entered' => '100.0000', 'recurring' => '10.0000', 'estimated' => '150.0000'], $november['expense']);
    }

    public function testOnlyTheLayersAsked(): void
    {
        [$november] = $this->report(['months' => 1, 'layers' => 'recurring'])['periods'];

        $this->assertSame(['entered' => '0.0000', 'recurring' => '10.0000', 'estimated' => '0.0000'], $november['expense']);
        $this->assertSame('3440.0000', $november['balance']);
    }

    public function testWeeksStartOnMondayAndDaysOnlyAFewMonthsAhead(): void
    {
        $weeks = $this->report(['months' => 1, 'by' => 'week'])['periods'];
        $this->assertSame(['2026-11-10', '2026-11-15'], [$weeks[0]['start'], $weeks[0]['end']]);
        $this->assertSame('2026-11-16', $weeks[1]['start']);
        $this->assertSame('2026-12-13', end($weeks)['end']);

        $days = $this->report(['months' => 1, 'by' => 'day'])['periods'];
        $this->assertSame('2026-12-10', end($days)['end']);
        $this->assertSame('10.0000', $days[10]['expense']['recurring'], 'the phone on the 20th');

        $this->client->request('GET', '/api/reports/cash-flow', ['months' => 12, 'by' => 'day']);
        $this->assertResponseStatusCodeSame(400);
    }

    /** @param array<string, int|string> $query */
    private function report(array $query): array
    {
        $this->client->request('GET', '/api/reports/cash-flow', $query);
        $this->assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function transaction(Account $source, Account $destination, string $at, string $amount): Transaction
    {
        $transaction = new Transaction($this->user, $source, $destination, new \DateTimeImmutable($at), $amount, 'EUR');
        $transaction->setDescription($destination->getName());
        $this->entityManager->persist($transaction);

        return $transaction;
    }
}
