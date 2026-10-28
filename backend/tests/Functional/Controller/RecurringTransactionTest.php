<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\Currency;
use App\Entity\Transaction;
use App\Entity\User;
use App\Recurring\Service\RecurringTransactionService;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Recurring transactions over HTTP, and the scheduled task creating them on
 * their day at their time, never twice.
 */
class RecurringTransactionTest extends WebTestCaseBase
{
    use ClockSensitiveTrait;

    private KernelBrowser $client;
    private User $user;
    private Account $checking;
    private Account $savings;
    private Account $phone;

    protected function setUp(): void
    {
        self::mockTime('2026-10-28 10:00:00 Europe/Rome');
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
        $this->savings = new Account($this->user, AccountType::Asset, 'Savings');
        $this->savings->setAssetKind(AssetAccountKind::Checking);
        $this->savings->setCurrency($currency);
        $this->phone = new Account($this->user, AccountType::Expense, 'Phone company');
        foreach ([$this->checking, $this->savings, $this->phone] as $account) {
            $entityManager->persist($account);
        }
        $entityManager->flush();
    }

    public function testAMonthlyExpenseIsCreatedOnItsDayAtItsTimeAndNeverTwice(): void
    {
        $recurring = $this->create(['description' => 'SIM', 'amount' => '4.99', 'startDate' => '2026-01-08', 'time' => '13:00']);

        $this->assertSame('withdrawal', $recurring['kind']);
        $this->assertSame('2026-11-08', $recurring['nextOccurrence'], 'nothing before the day it is made');
        $this->assertSame('4.9900', $recurring['monthlyAmount']);

        self::mockTime('2026-11-08 12:30:00 Europe/Rome');
        $this->assertSame(0, $this->createDue()['created'], 'not before its time');

        self::mockTime('2026-11-08 13:15:00 Europe/Rome');
        $this->assertSame(1, $this->createDue()['created']);
        $this->assertSame(0, $this->createDue()['created'], 'never twice');

        $list = $this->get("/api/transactions?kind=cash&recurringId={$recurring['id']}");
        $this->assertSame(1, $list['total']);
        $this->assertSame('2026-11-08T13:00:00', $list['items'][0]['occurredAt']);
        $this->assertSame('4.9900', $list['items'][0]['amount']);
        $this->assertSame('SIM', $list['items'][0]['recurringDescription']);

        self::mockTime('2027-01-10 09:00:00 Europe/Rome');
        $this->assertSame(2, $this->createDue()['created'], 'catches up on what was missed');
    }

    public function testOneToConfirmWaitsAndTakesTheAmountActuallyPaid(): void
    {
        $recurring = $this->create(['description' => 'Electricity', 'amount' => '80', 'startDate' => '2026-11-03', 'mode' => 'confirm']);

        self::mockTime('2026-12-05 09:00:00 Europe/Rome');
        $this->assertSame(0, $this->createDue()['created'], 'waits for the user');

        $pending = $this->get('/api/recurring-transactions/pending');
        $this->assertCount(1, $pending);
        $this->assertSame('2026-11-03', $pending[0]['day'], 'the oldest first');
        $this->assertSame(2, $pending[0]['recurring']['pending']);

        $this->post("/api/recurring-transactions/{$recurring['id']}/confirm", ['amount' => '92.40']);
        $this->assertResponseStatusCodeSame(201);
        $transaction = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('92.4000', $transaction['amount']);
        $this->assertStringStartsWith('2026-11-03', $transaction['occurredAt']);

        $this->post("/api/recurring-transactions/{$recurring['id']}/skip", []);
        $this->assertResponseIsSuccessful();
        $this->post("/api/recurring-transactions/{$recurring['id']}/skip", []);
        $this->assertResponseStatusCodeSame(409, 'nothing left to confirm');
    }

    public function testMadeFromATransactionThatCountsAsTheFirst(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $transaction = new Transaction($this->user, $this->checking, $this->phone, new \DateTimeImmutable('2026-10-28T09:00:00'), '4.9900', 'EUR');
        $transaction->setDescription('SIM');
        $entityManager->persist($transaction);
        $entityManager->flush();

        $recurring = $this->create(['description' => 'SIM', 'amount' => '4.99', 'startDate' => '2026-10-28', 'time' => '09:00', 'fromTransactionId' => (string) $transaction->getId()]);

        $this->assertSame('2026-11-28', $recurring['nextOccurrence']);
        self::mockTime('2026-10-28 23:00:00 Europe/Rome');
        $this->assertSame(0, $this->createDue()['created']);
        $this->assertSame(1, $this->get("/api/transactions?kind=cash&recurringId={$recurring['id']}")['total']);
    }

    public function testWhatFallsWhilePausedIsLetGo(): void
    {
        $recurring = $this->create(['description' => 'Gym', 'amount' => '30', 'startDate' => '2026-11-01', 'time' => '08:00']);
        $this->post("/api/recurring-transactions/{$recurring['id']}/pause", []);

        self::mockTime('2027-01-15 09:00:00 Europe/Rome');
        $this->assertSame(0, $this->createDue()['created']);

        $this->post("/api/recurring-transactions/{$recurring['id']}/resume", []);
        $resumed = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('2027-02-01', $resumed['nextOccurrence']);
        $this->assertSame(0, $this->createDue()['created']);
    }

    public function testTheTemplateIsCheckedAsATransactionIs(): void
    {
        $this->post('/api/recurring-transactions', [
            'description' => 'Wrong', 'amount' => '10', 'startDate' => '2026-11-01',
            'sourceAccountId' => (string) $this->phone->getId(), 'destinationAccountId' => (string) $this->checking->getId(),
        ]);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('account_pair_not_allowed', json_decode((string) $this->client->getResponse()->getContent(), true)['code']);
    }

    public function testUpcomingListsWhatComesByDay(): void
    {
        $this->create(['description' => 'SIM', 'amount' => '4.99', 'startDate' => '2026-01-08']);
        $this->create([
            'description' => 'To savings', 'amount' => '200', 'startDate' => '2026-01-01', 'unit' => 'month', 'monthDay' => 'last_day',
            'destinationAccountId' => (string) $this->savings->getId(),
        ]);

        $upcoming = $this->get('/api/recurring-transactions/upcoming?days=30');

        $this->assertSame(['2026-10-31', '2026-11-08'], array_column($upcoming, 'day'), 'Nov 30 is past the 30 days');
        $this->assertSame('transfer', $upcoming[0]['recurring']['kind']);
    }

    public function testDeletingTheAccountMovesItsRecurringTransactions(): void
    {
        $recurring = $this->create(['description' => 'SIM', 'amount' => '4.99', 'startDate' => '2026-01-08']);

        $this->client->request('DELETE', "/api/accounts/{$this->checking->getId()}");
        $this->assertResponseStatusCodeSame(409, 'in use by a recurring transaction');

        $this->client->request('DELETE', "/api/accounts/{$this->checking->getId()}?transferTo={$this->savings->getId()}");
        $this->assertResponseIsSuccessful();
        $this->assertSame((string) $this->savings->getId(), $this->get("/api/recurring-transactions/{$recurring['id']}")['sourceAccount']['id']);
    }

    public function testADeletedOneLeavesItsTransactions(): void
    {
        $recurring = $this->create(['description' => 'SIM', 'amount' => '4.99', 'startDate' => '2026-01-08', 'time' => '13:00']);
        self::mockTime('2026-11-08 14:00:00 Europe/Rome');
        $this->createDue();

        $this->client->request('DELETE', "/api/recurring-transactions/{$recurring['id']}");
        $this->assertResponseStatusCodeSame(204);

        $transactions = $this->get('/api/transactions?kind=cash');
        $this->assertSame(1, $transactions['total']);
        $this->assertNull($transactions['items'][0]['recurringId']);
        $this->assertSame([], $this->get('/api/recurring-transactions'));
    }

    public function testAPlannedAmountChangeTakesOverFromItsDay(): void
    {
        $recurring = $this->create([
            'description' => 'Netflix', 'amount' => '12.99', 'startDate' => '2026-01-15', 'time' => '09:00',
            'amountChanges' => [['from' => '2026-12-01', 'amount' => '13.99']],
        ]);

        $this->assertSame('12.9900', $recurring['nextAmount'], 'November is still the old price');
        $this->assertSame([['from' => '2026-12-01', 'amount' => '13.9900']], $recurring['amountChanges']);
        $upcoming = $this->get('/api/recurring-transactions/upcoming?days=60');
        $this->assertSame(['12.9900', '13.9900'], array_column($upcoming, 'amount'));
        // Twelve months: November at the old price, the other eleven at the new one.
        $this->assertSame(bcdiv(bcadd('12.99', bcmul('13.99', '11', 4), 4), '12', 4), $recurring['monthlyAmount']);

        self::mockTime('2026-12-15 10:00:00 Europe/Rome');
        $this->createDue();
        $this->assertSame(['13.9900', '12.9900'], array_column($this->get("/api/transactions?kind=cash&recurringId={$recurring['id']}")['items'], 'amount'));

        // Saved once its day has come, the change is the amount.
        $this->client->request('PUT', "/api/recurring-transactions/{$recurring['id']}", server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'description' => 'Netflix', 'amount' => '12.99', 'startDate' => '2026-01-15', 'time' => '09:00',
            'sourceAccountId' => (string) $this->checking->getId(), 'destinationAccountId' => (string) $this->phone->getId(),
            'amountChanges' => $recurring['amountChanges'],
        ]));
        $saved = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('13.9900', $saved['amount']);
        $this->assertSame([], $saved['amountChanges']);
    }

    public function testTheHistorySaysWhatChanged(): void
    {
        $recurring = $this->create(['description' => 'SIM', 'amount' => '4.99', 'startDate' => '2026-01-08']);
        $this->client->request('PUT', "/api/recurring-transactions/{$recurring['id']}", server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'description' => 'SIM Iliad', 'amount' => '4.99', 'startDate' => '2026-01-08',
            'sourceAccountId' => (string) $this->checking->getId(), 'destinationAccountId' => (string) $this->phone->getId(),
        ]));
        $this->post("/api/recurring-transactions/{$recurring['id']}/pause", []);

        $log = $this->get("/api/recurring-transactions/{$recurring['id']}/audit-log");

        $this->assertSame(['updated', 'updated', 'created'], array_column($log, 'action'));
        $this->assertSame([['field' => 'paused', 'oldValue' => false, 'newValue' => true]], $log[0]['changes']);
        $this->assertSame([['field' => 'description', 'oldValue' => 'SIM', 'newValue' => 'SIM Iliad']], $log[1]['changes']);
    }

    public function testOneEnteredByHandIsRecognisedWhenItArrives(): void
    {
        $recurring = $this->create(['description' => 'Electricity', 'amount' => '80', 'startDate' => '2026-11-03', 'mode' => 'confirm']);

        // Paid a day late, a different amount: still the one.
        self::mockTime('2026-11-05 09:00:00 Europe/Rome');
        $transactionId = $this->enter('2026-11-04T10:00:00', '93.10');
        $this->assertSame(['linked' => 1, 'reminded' => 0], $this->followManual());

        $this->assertSame($recurring['id'], $this->get("/api/transactions/{$transactionId}")['recurringId']);
        $this->assertSame([], $this->get('/api/recurring-transactions/pending'));
        $this->assertSame('2026-12-03', $this->get("/api/recurring-transactions/{$recurring['id']}")['nextOccurrence']);
    }

    public function testAMissingOneIsRemindedAfterSomeDaysUntilDealtWith(): void
    {
        $recurring = $this->create(['description' => 'Electricity', 'amount' => '80', 'startDate' => '2026-11-03', 'mode' => 'confirm', 'reminderDays' => 3]);

        self::mockTime('2026-11-05 09:00:00 Europe/Rome');
        $this->assertSame(0, $this->followManual()['reminded'], 'not before its days');

        self::mockTime('2026-11-06 09:00:00 Europe/Rome');
        $this->assertSame(1, $this->followManual()['reminded']);
        $this->assertSame(0, $this->followManual()['reminded'], 'one reminder, not one per run');
        $notifications = $this->get('/api/notifications');
        $this->assertCount(1, $notifications);
        $this->assertSame('recurring_missing', $notifications[0]['type']);
        $this->assertSame(['recurringId' => $recurring['id'], 'description' => 'Electricity', 'day' => '2026-11-03'], $notifications[0]['params']);

        // Entered to another payee, so not recognised: linked by hand.
        $this->post('/api/accounts/expense', ['type' => 'expense', 'name' => 'Other company']);
        $otherId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];
        $transactionId = $this->enter('2026-11-02T10:00:00', '81', $otherId);
        $this->assertSame([$transactionId], array_column($this->get("/api/recurring-transactions/{$recurring['id']}/linkable"), 'id'));
        $this->post("/api/recurring-transactions/{$recurring['id']}/link", ['transactionId' => $transactionId]);
        $this->assertResponseIsSuccessful();

        $this->assertSame([], $this->get('/api/notifications'), 'dealt with: the reminder is gone');
        $this->post("/api/recurring-transactions/{$recurring['id']}/link", ['transactionId' => $transactionId]);
        $this->assertResponseStatusCodeSame(409, 'nothing waiting any more');
    }

    public function testADismissedReminderStaysHidden(): void
    {
        $this->create(['description' => 'Electricity', 'amount' => '80', 'startDate' => '2026-11-03', 'mode' => 'confirm']);
        self::mockTime('2026-11-07 09:00:00 Europe/Rome');
        $this->followManual();
        $id = $this->get('/api/notifications')[0]['id'];

        $this->post("/api/notifications/{$id}/dismiss", []);
        $this->followManual();
        $this->assertSame([], $this->get('/api/notifications'));
    }

    private function enter(string $occurredAt, string $amount, ?string $destinationId = null): string
    {
        $this->post('/api/transactions', [
            'description' => 'Bill', 'occurredAt' => $occurredAt, 'amount' => $amount, 'timezone' => 'Europe/Rome',
            'sourceAccountId' => (string) $this->checking->getId(), 'destinationAccountId' => $destinationId ?? (string) $this->phone->getId(),
        ]);
        $this->assertResponseStatusCodeSame(201);

        return json_decode((string) $this->client->getResponse()->getContent(), true)['id'];
    }

    /** @return array{linked: int, reminded: int} */
    private function followManual(): array
    {
        return static::getContainer()->get(RecurringTransactionService::class)->followManual();
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function create(array $fields): array
    {
        $this->post('/api/recurring-transactions', $fields + [
            'sourceAccountId' => (string) $this->checking->getId(),
            'destinationAccountId' => (string) $this->phone->getId(),
            'timezone' => 'Europe/Rome',
        ]);
        $this->assertResponseStatusCodeSame(201);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return array{created: int, failed: int} */
    private function createDue(): array
    {
        return static::getContainer()->get(RecurringTransactionService::class)->createDue();
    }

    /** @param array<string, mixed> $body */
    private function post(string $url, array $body): void
    {
        $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($body));
    }

    /** @return array<mixed> */
    private function get(string $url): array
    {
        $this->client->request('GET', $url);
        $this->assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
