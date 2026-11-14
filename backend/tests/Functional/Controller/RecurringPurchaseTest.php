<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\Currency;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentInstrumentType;
use App\Entity\InvestmentPrice;
use App\Entity\User;
use App\Recurring\Service\RecurringTransactionService;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Recurring purchases (PAC): always entered by the user with the broker's
 * figures, estimated from the latest price as their plan mode says, linked
 * to the trade that records them; and skipping the next occurrence of any
 * recurrence.
 */
class RecurringPurchaseTest extends WebTestCaseBase
{
    use ClockSensitiveTrait;

    private KernelBrowser $client;
    private User $user;
    private Account $broker;
    private Account $checking;
    private Account $phone;
    private InvestmentInstrument $etf;

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
        $this->broker = new Account($this->user, AccountType::Asset, 'Broker');
        $this->broker->setAssetKind(AssetAccountKind::Investment);
        $this->broker->setCurrency($currency);
        $this->checking = new Account($this->user, AccountType::Asset, 'Checking');
        $this->checking->setAssetKind(AssetAccountKind::Checking);
        $this->checking->setCurrency($currency);
        $this->phone = new Account($this->user, AccountType::Expense, 'Phone company');
        $this->etf = new InvestmentInstrument($this->user, 'World ETF', InvestmentInstrumentType::Etf);
        foreach ([$this->broker, $this->checking, $this->phone, $this->etf] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->persist(new InvestmentPrice($this->etf, new \DateTimeImmutable('2026-10-27'), '30.0000'));
        $entityManager->flush();
    }

    public function testAFixedAmountIsBoughtInFractionalUnits(): void
    {
        $plan = $this->createPlan(['planMode' => 'amount', 'amount' => '100', 'fees' => '1.5']);

        $this->assertSame('purchase', $plan['kind']);
        $this->assertSame('confirm', $plan['mode'], 'always entered with the broker\'s figures');
        $this->assertSame('World ETF', $plan['instrument']['name']);
        $this->assertSame('1.5000', $plan['fees']);
        $this->assertSame('3.333333', $plan['estimate']['quantity']);
        $this->assertSame('100.0000', $plan['estimate']['amount']);
        $this->assertSame('2026-10-27', $plan['estimate']['priceDate']);
        $this->assertSame('100.0000', $plan['monthlyAmount']);
        $this->assertNull($plan['categoryId']);
    }

    public function testAFixedAmountInWholeUnitsBuysWhatFits(): void
    {
        $plan = $this->createPlan(['planMode' => 'whole_units', 'amount' => '100']);

        $this->assertSame('3', $plan['estimate']['quantity']);
        $this->assertSame('90.00', $plan['estimate']['amount']);
    }

    public function testFixedUnitsCostWhatThePriceSays(): void
    {
        $plan = $this->createPlan(['planMode' => 'units', 'quantity' => '2']);

        $this->assertSame('2.000000', $plan['quantity']);
        $this->assertSame('60.00', $plan['estimate']['amount']);
        $this->assertSame('60.0000', $plan['monthlyAmount']);

        $this->post('/api/recurring-transactions', $this->planFields(['planMode' => 'units']));
        $this->assertResponseStatusCodeSame(422, 'units mode needs the units');
    }

    public function testOnlyOnAnInvestmentAccount(): void
    {
        $this->post('/api/recurring-transactions', $this->planFields(['planMode' => 'amount', 'amount' => '100', 'sourceAccountId' => (string) $this->checking->getId()]));
        $this->assertFalse($this->client->getResponse()->isSuccessful());
    }

    public function testThePurchaseEnteredFromItsReminderIsItsOccurrence(): void
    {
        $plan = $this->createPlan(['planMode' => 'amount', 'amount' => '100']);

        self::mockTime('2026-11-05 09:00:00 Europe/Rome');
        $pending = $this->get('/api/recurring-transactions/pending');
        $this->assertCount(1, $pending);
        $this->assertSame('2026-11-05', $pending[0]['day']);
        $this->assertSame('100.0000', $pending[0]['amount']);

        $trade = $this->buy('2026-11-05T09:00:00', '3.21', $plan['id']);
        $this->assertSame($plan['id'], $trade['recurringId']);
        $this->assertSame('PAC', $trade['recurringDescription']);
        $this->assertSame([], $this->get('/api/recurring-transactions/pending'));
        $this->assertSame('2026-12-05', $this->get("/api/recurring-transactions/{$plan['id']}")['nextOccurrence']);
        $this->assertSame(1, $this->get("/api/investment-transactions?recurringId={$plan['id']}")['total']);

        $this->post("/api/recurring-transactions/{$plan['id']}/confirm", []);
        $this->assertResponseStatusCodeSame(409, 'a purchase is never confirmed as a money transaction');
    }

    public function testAPurchaseEnteredFromTheTradesIsRecognised(): void
    {
        $plan = $this->createPlan(['planMode' => 'amount', 'amount' => '100']);

        self::mockTime('2026-11-06 09:00:00 Europe/Rome');
        $this->buy('2026-11-06T09:00:00', '3.2');
        $this->assertSame(['linked' => 1, 'reminded' => 0], $this->followManual());
        $this->assertSame([], $this->get('/api/recurring-transactions/pending'));
        $this->assertSame(1, $this->get("/api/investment-transactions?recurringId={$plan['id']}")['total']);
    }

    public function testMadeFromAPurchaseThatCountsAsTheFirst(): void
    {
        $trade = $this->buy('2026-11-03T10:00:00', '3.3');

        $plan = $this->createPlan(['planMode' => 'amount', 'amount' => '100', 'startDate' => '2026-11-03', 'fromTradeId' => $trade['id']]);
        $this->assertSame('2026-12-03', $plan['nextOccurrence'], 'the purchase it is made from is the first');
        $this->assertSame($plan['id'], $this->get("/api/investment-transactions/{$trade['id']}")['recurringId']);

        $this->post('/api/recurring-transactions', $this->planFields(['planMode' => 'amount', 'amount' => '100', 'fromTradeId' => $trade['id']]));
        $this->assertResponseStatusCodeSame(409, 'already part of a plan');
    }

    public function testSkippingLetsTheNextOccurrenceGoWhateverTheMode(): void
    {
        $this->post('/api/recurring-transactions', [
            'description' => 'SIM', 'amount' => '4.99', 'startDate' => '2026-01-08', 'timezone' => 'Europe/Rome',
            'sourceAccountId' => (string) $this->checking->getId(), 'destinationAccountId' => (string) $this->phone->getId(),
        ]);
        $this->assertResponseStatusCodeSame(201);
        $sim = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('2026-11-08', $sim['nextOccurrence']);

        $this->post("/api/recurring-transactions/{$sim['id']}/skip", []);
        $this->assertResponseIsSuccessful();
        $this->assertSame('2026-12-08', $this->get("/api/recurring-transactions/{$sim['id']}")['nextOccurrence'], 'a free month');

        self::mockTime('2026-11-09 09:00:00 Europe/Rome');
        $this->assertSame(0, static::getContainer()->get(RecurringTransactionService::class)->createDue()['created']);

        $history = $this->get("/api/recurring-transactions/{$sim['id']}/audit-log");
        $this->assertContains(['field' => 'skipped', 'oldValue' => null, 'newValue' => '2026-11-08'], array_merge(...array_map(static fn (array $entry) => $entry['changes'] ?? [], $history)));
    }

    public function testDeletingTheInstrumentDeletesItsPlans(): void
    {
        $plan = $this->createPlan(['planMode' => 'amount', 'amount' => '100']);

        $this->assertSame(1, $this->get("/api/investment-instruments/{$this->etf->getId()}/deletion-preview")['recurring']);
        $this->client->request('DELETE', "/api/investment-instruments/{$this->etf->getId()}");
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', "/api/recurring-transactions/{$plan['id']}");
        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function createPlan(array $fields): array
    {
        $this->post('/api/recurring-transactions', $this->planFields($fields));
        $this->assertResponseStatusCodeSame(201);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function planFields(array $fields): array
    {
        return $fields + [
            'description' => 'PAC',
            'amount' => null,
            'startDate' => '2026-01-05',
            'sourceAccountId' => (string) $this->broker->getId(),
            'instrumentId' => (string) $this->etf->getId(),
            'timezone' => 'Europe/Rome',
            // Asked for, but a purchase is always entered by the user.
            'mode' => 'auto',
        ];
    }

    /** @return array<string, mixed> */
    private function buy(string $occurredAt, string $quantity, ?string $recurringId = null): array
    {
        $this->post('/api/investment-transactions', [
            'accountId' => (string) $this->broker->getId(), 'instrumentId' => (string) $this->etf->getId(), 'type' => 'buy',
            'quantity' => $quantity, 'unitPrice' => '31', 'amount' => '99.51', 'occurredAt' => $occurredAt, 'timezone' => 'Europe/Rome',
            'recurringId' => $recurringId,
        ]);
        $this->assertResponseStatusCodeSame(201);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return array{linked: int, reminded: int} */
    private function followManual(): array
    {
        return static::getContainer()->get(RecurringTransactionService::class)->followManual();
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
