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
