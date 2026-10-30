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
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Drafts: what comes from outside, checked against what is there (an
 * identical transaction, a recurring one expected), else reviewed by the
 * user: accepted (split as they like) or discarded.
 */
class DraftTest extends WebTestCaseBase
{
    use ClockSensitiveTrait;

    private KernelBrowser $client;
    private User $user;
    private string $checking;
    private string $restaurant;

    protected function setUp(): void
    {
        self::mockTime('2026-10-28 20:00:00 Europe/Rome');
        $this->client = static::createClient();
        $this->user = static::createUser();
        static::authenticate($this->client, $this->user);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $currency = $entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']) ?? new Currency('EUR', 'Euro', '€', 2);
        $entityManager->persist($currency);
        $checking = new Account($this->user, AccountType::Asset, 'Checking');
        $checking->setAssetKind(AssetAccountKind::Checking);
        $checking->setCurrency($currency);
        $restaurant = new Account($this->user, AccountType::Expense, 'Da Mario');
        $entityManager->persist($checking);
        $entityManager->persist($restaurant);
        $entityManager->persist(new Transaction($this->user, $checking, $restaurant, new \DateTimeImmutable('2026-10-27T13:00:00'), '50.0000', 'EUR'));
        $entityManager->flush();
        $this->checking = (string) $checking->getId();
        $this->restaurant = (string) $restaurant->getId();
    }

    public function testADraftWaitsAndIsAcceptedSplitAsTheUserWants(): void
    {
        $draft = $this->draft(['amount' => '50', 'occurredAt' => '2026-10-25T21:30:00', 'description' => 'Cena', 'sourceAccountId' => $this->checking, 'direction' => 'out']);
        $this->assertSame('pending', $draft['status']);
        $this->assertNull($draft['possibleDuplicate'], 'the 50 at Da Mario is two days later: another one');
        $this->assertSame(['draft_new'], array_column($this->get('/api/notifications'), 'type'));

        // 25 dinner, 25 a friend's: recorded as taken out, no account on the other side.
        $this->post("/api/drafts/{$draft['id']}/accept", ['parts' => [
            ['description' => 'Cena', 'amount' => '25', 'occurredAt' => '2026-10-25T21:30:00', 'sourceAccountId' => $this->checking, 'destinationAccountId' => $this->restaurant],
            ['description' => 'Prelievo per Marco', 'amount' => '25', 'occurredAt' => '2026-10-25T21:30:00', 'sourceAccountId' => $this->checking, 'destinationAccountId' => null],
        ]]);
        $this->assertResponseIsSuccessful();
        $this->assertCount(2, json_decode((string) $this->client->getResponse()->getContent(), true));
        $this->assertSame([], $this->get('/api/drafts'));
        $this->assertSame([], $this->get('/api/notifications'));

        $this->post("/api/drafts/{$draft['id']}/discard", []);
        $this->assertResponseStatusCodeSame(409, 'dealt with already');
    }

    public function testAWrongPartIsNamedAndNothingIsRecorded(): void
    {
        $draft = $this->draft(['amount' => '30', 'occurredAt' => '2026-10-20T10:00:00']);

        $this->post("/api/drafts/{$draft['id']}/accept", ['parts' => [
            ['description' => 'Ok', 'amount' => '10', 'occurredAt' => '2026-10-20T10:00:00', 'sourceAccountId' => $this->checking],
            ['description' => 'Wrong', 'amount' => '-5', 'occurredAt' => '2026-10-20T10:00:00', 'sourceAccountId' => $this->checking],
        ]]);

        $this->assertResponseStatusCodeSame(422);
        $violations = json_decode((string) $this->client->getResponse()->getContent(), true)['violations'];
        $this->assertSame(['parts[1].amount'], array_column($violations, 'propertyPath'));
        $this->assertSame(1, $this->get('/api/transactions?kind=cash')['total'], 'only the one from before');
    }

    public function testAnIdenticalTransactionIsHandledAsTheSenderAsks(): void
    {
        $base = ['amount' => '50', 'occurredAt' => '2026-10-27T13:05:00', 'sourceAccountId' => $this->checking, 'description' => 'POS DA MARIO'];

        $review = $this->draft($base);
        $this->assertSame('pending', $review['status']);
        $this->assertSame('50.0000', $review['possibleDuplicate']['amount']);

        $this->assertSame('matched', $this->draft([...$base, 'ifExists' => 'skip'])['status']);

        $this->draft([...$base, 'ifExists' => 'annotate', 'notes' => 'carta *1234']);
        $list = $this->get('/api/transactions?kind=cash');
        $this->assertSame("POS DA MARIO · carta *1234", $list['items'][0]['notes']);

        $this->assertSame('pending', $this->draft([...$base, 'ifExists' => 'create'])['status']);
    }

    public function testAnExpectedRecurringTransactionIsRecordedOnTheSpot(): void
    {
        $this->post('/api/recurring-transactions', [
            'description' => 'SIM', 'amount' => '4.99', 'startDate' => '2026-01-08', 'time' => '13:00', 'timezone' => 'Europe/Rome',
            'sourceAccountId' => $this->checking, 'destinationAccountId' => $this->restaurant,
        ]);
        $recurringId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        self::mockTime('2026-11-08 08:10:00 Europe/Rome');
        $draft = $this->draft(['amount' => '4.99', 'occurredAt' => '2026-11-08T08:09:00', 'direction' => 'out', 'description' => 'ILIAD']);

        $this->assertSame('matched', $draft['status']);
        $transaction = $this->get("/api/transactions/{$draft['transactionIds'][0]}");
        $this->assertSame($recurringId, $transaction['recurringId']);
        $this->assertSame('2026-11-08T08:09:00', $transaction['occurredAt'], 'when it really happened');
        $this->assertSame('2026-12-08', $this->get("/api/recurring-transactions/{$recurringId}")['nextOccurrence']);
        $this->assertSame(['draft_recorded'], array_column($this->get('/api/notifications'), 'type'));
    }

    public function testATransactionSentWithIfExistsReviewBecomesADraft(): void
    {
        $this->post('/api/transactions', [
            'description' => 'Da Mario', 'amount' => '50', 'occurredAt' => '2026-10-27T13:00:00', 'timezone' => 'Europe/Rome',
            'sourceAccountId' => $this->checking, 'destinationAccountId' => $this->restaurant, 'ifExists' => 'review',
        ]);
        $this->assertResponseStatusCodeSame(202);
        $this->assertSame('pending', json_decode((string) $this->client->getResponse()->getContent(), true)['status']);

        $this->post('/api/transactions', [
            'description' => 'Da Mario', 'amount' => '50', 'occurredAt' => '2026-10-27T13:00:00', 'timezone' => 'Europe/Rome',
            'sourceAccountId' => $this->checking, 'destinationAccountId' => $this->restaurant, 'ifExists' => 'skip',
        ]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame(1, $this->get('/api/transactions?kind=cash')['total']);
    }

    public function testADraftSuggestsThePayeeAndCategoryFromWhatCameBefore(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $checking = $entityManager->find(Account::class, $this->checking);
        $grocer = new Account($this->user, AccountType::Expense, 'Esselunga');
        $bar = new Account($this->user, AccountType::Expense, 'Bar Centrale');
        $groceries = new Category($this->user, 'Spesa');
        $entityManager->persist($grocer);
        $entityManager->persist($bar);
        $entityManager->persist($groceries);
        $past = new Transaction($this->user, $checking, $grocer, new \DateTimeImmutable('2026-10-01T10:00:00'), '80.0000', 'EUR');
        $past->setDescription('Esselunga');
        $past->setCategory($groceries);
        $entityManager->persist($past);
        $entityManager->flush();

        $shop = $this->draft(['amount' => '42', 'occurredAt' => '2026-10-28T18:00:00', 'description' => 'ESSELUNGA MILANO 1234', 'sourceAccountId' => $this->checking, 'direction' => 'out']);
        $suggestion = $this->get("/api/drafts/{$shop['id']}")['suggestion'];
        $this->assertSame('withdrawal', $suggestion['kind']);
        $this->assertSame('Esselunga', $suggestion['account']['name'], 'the past description is in the new one');
        $this->assertSame((string) $groceries->getId(), $suggestion['categoryId']);

        $dinner = $this->draft(['amount' => '30', 'occurredAt' => '2026-10-28T21:00:00', 'description' => 'DA MARIO', 'sourceAccountId' => $this->checking, 'direction' => 'out']);
        $this->assertSame($this->restaurant, $this->get("/api/drafts/{$dinner['id']}")['suggestion']['account']['id'], 'named like a payee');

        $coffee = $this->draft(['amount' => '2', 'occurredAt' => '2026-10-28T08:00:00', 'description' => 'BAR CENTRLE', 'sourceAccountId' => $this->checking, 'direction' => 'out']);
        $suggestion = $this->get("/api/drafts/{$coffee['id']}")['suggestion'];
        $this->assertNull($suggestion['account']);
        $this->assertSame(['Bar Centrale'], array_column($suggestion['similar'], 'name'), 'misspelt: offered, not chosen');
    }

    public function testAPayeeGivesTheDescriptionAndCategoriesUsedWithIt(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $checking = $entityManager->find(Account::class, $this->checking);
        $card = new Account($this->user, AccountType::Asset, 'Card');
        $card->setAssetKind(AssetAccountKind::Checking);
        $card->setCurrency($checking->getCurrency());
        $fibre = new Account($this->user, AccountType::Expense, 'Dimensione');
        $amazon = new Account($this->user, AccountType::Expense, 'Amazon');
        $home = new Category($this->user, 'Casa');
        $tech = new Category($this->user, 'Elettronica');
        foreach ([$card, $fibre, $amazon, $home, $tech] as $entity) {
            $entityManager->persist($entity);
        }
        $past = function (Account $from, Account $to, string $day, string $description, Category $category) use ($entityManager): void {
            $transaction = new Transaction($this->user, $from, $to, new \DateTimeImmutable($day.'T10:00:00'), '29.9000', 'EUR');
            $transaction->setDescription($description);
            $transaction->setCategory($category);
            $entityManager->persist($transaction);
        };
        $past($checking, $fibre, '2026-08-05', 'Fibra casa', $home);
        $past($card, $fibre, '2026-09-05', 'Fibra casa', $home);
        $past($checking, $amazon, '2026-09-10', 'Mobiletto', $home);
        $past($card, $amazon, '2026-10-10', 'Cavo USB', $tech);
        $entityManager->flush();

        $bill = $this->draft(['amount' => '29.90', 'occurredAt' => '2026-10-28T08:00:00', 'description' => 'DIMENSIONE', 'sourceAccountId' => $this->checking, 'direction' => 'out']);
        $suggestion = $this->get("/api/drafts/{$bill['id']}")['suggestion'];
        $this->assertSame(['Dimensione', 'Fibra casa', (string) $home->getId()], [$suggestion['account']['name'], $suggestion['description'], $suggestion['categoryId']], 'the latest ones agree, whichever account paid');

        $parcel = $this->draft(['amount' => '15', 'occurredAt' => '2026-10-28T09:00:00', 'description' => 'AMAZON', 'sourceAccountId' => $this->checking, 'direction' => 'out']);
        $suggestion = $this->get("/api/drafts/{$parcel['id']}")['suggestion'];
        $this->assertNull($suggestion['description'], 'they differ: nothing chosen');
        $this->assertSame((string) $tech->getId(), $suggestion['categoryId'], 'the latest');
        $this->assertSame(['Cavo USB', 'Mobiletto'], array_column($suggestion['recent']['descriptions'], 'description'));
        $this->assertSame([(string) $tech->getId(), (string) $home->getId()], $suggestion['recent']['categories']);
        $this->assertSame($suggestion['recent']['descriptions'], $this->get('/api/drafts/payees/'.$amazon->getId())['descriptions']);

        // Recorded as "Fibra casa", the bank's "DIMENSIONE" still leads to its payee.
        $this->post("/api/drafts/{$bill['id']}/accept", ['parts' => [
            ['description' => 'Fibra casa', 'amount' => '29.90', 'occurredAt' => '2026-10-28T08:00:00', 'sourceAccountId' => $this->checking, 'destinationAccountId' => (string) $fibre->getId()],
        ]]);
        $fibre->setName('Linkem');
        $entityManager->flush();
        $next = $this->draft(['amount' => '29.90', 'occurredAt' => '2026-10-28T19:00:00', 'description' => 'DIMENSIONE', 'sourceAccountId' => $this->checking, 'direction' => 'out']);
        $this->assertSame((string) $fibre->getId(), $this->get("/api/drafts/{$next['id']}")['suggestion']['account']['id']);
    }

    public function testMoneyFromOrToTheUserThemselfIsATransfer(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->user->setFirstName('Nicolò');
        $this->user->setLastName('Pozzato');
        $checking = $entityManager->find(Account::class, $this->checking);
        $broker = new Account($this->user, AccountType::Asset, 'Trade Republic');
        $broker->setAssetKind(AssetAccountKind::Checking);
        $broker->setCurrency($checking->getCurrency());
        $entityManager->persist($broker);
        $entityManager->persist(new Transaction($this->user, $checking, $broker, new \DateTimeImmutable('2026-10-01T10:00:00'), '500.0000', 'EUR'));
        $entityManager->flush();

        foreach (["Nicolo' Pozzato", 'NICOLO', 'Pozzato Nicolò'] as $i => $name) {
            $in = $this->draft(['amount' => (string) (200 + $i), 'occurredAt' => '2026-10-28T10:00:00', 'description' => $name, 'destinationAccountId' => (string) $broker->getId(), 'direction' => 'in']);
            $suggestion = $this->get("/api/drafts/{$in['id']}")['suggestion'];
            $this->assertSame(['transfer', $this->checking], [$suggestion['kind'], $suggestion['account']['id'] ?? null], $name);
        }
        $other = $this->draft(['amount' => '10', 'occurredAt' => '2026-10-28T10:00:00', 'description' => 'Nicola Rossi', 'destinationAccountId' => (string) $broker->getId(), 'direction' => 'in']);
        $this->assertNotSame('transfer', $this->get("/api/drafts/{$other['id']}")['suggestion']['kind']);
    }

    public function testTheTwoLegsOfATransferAreOne(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $savings = new Account($this->user, AccountType::Asset, 'Revolut');
        $savings->setAssetKind(AssetAccountKind::Checking);
        $savings->setCurrency($entityManager->find(Account::class, $this->checking)->getCurrency());
        $entityManager->persist($savings);
        $entityManager->flush();
        $savingsId = (string) $savings->getId();

        $out = $this->draft(['amount' => '100', 'occurredAt' => '2026-10-28T09:00:00', 'description' => 'Bonifico', 'sourceAccountId' => $this->checking, 'direction' => 'out']);
        $in = $this->draft(['amount' => '100', 'occurredAt' => '2026-10-28T09:02:00', 'description' => 'Ricarica', 'destinationAccountId' => $savingsId, 'direction' => 'in']);

        $suggestion = $this->get("/api/drafts/{$out['id']}")['suggestion'];
        $this->assertSame(['transfer', $savingsId, $in['id']], [$suggestion['kind'], $suggestion['account']['id'], $suggestion['pairedDraftId']]);

        $this->post("/api/drafts/{$out['id']}/accept", ['parts' => [
            ['description' => 'Ricarica Revolut', 'amount' => '100', 'occurredAt' => '2026-10-28T09:00:00', 'sourceAccountId' => $this->checking, 'destinationAccountId' => $savingsId],
        ]]);
        $this->assertResponseIsSuccessful();
        $transfer = json_decode((string) $this->client->getResponse()->getContent(), true)[0];
        $this->assertSame([], $this->get('/api/drafts'), 'the other leg closed with it');
        $other = $this->get("/api/drafts/{$in['id']}");
        $this->assertSame(['matched', [$transfer['id']]], [$other['status'], $other['transactionIds']]);
        $this->assertEqualsCanonicalizing([$out['id'], $in['id']], array_column($this->get('/api/drafts/recent'), 'id'));
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function draft(array $fields): array
    {
        $this->post('/api/drafts', $fields + ['timezone' => 'Europe/Rome']);
        $this->assertResponseStatusCodeSame(201);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
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
