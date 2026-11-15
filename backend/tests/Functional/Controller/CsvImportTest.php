<?php

namespace App\Tests\Functional\Controller;

use App\Transaction\Service\DescriptionHistory;
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

/** A bank's file: what each row likely is (duplicate, recurring, the shop and category of before), then imported all or none. */
class CsvImportTest extends WebTestCaseBase
{
    use ClockSensitiveTrait;

    private KernelBrowser $client;
    private string $checking;
    private string $shop;
    private string $groceries;

    protected function setUp(): void
    {
        self::mockTime('2026-10-28 10:00:00 Europe/Rome');
        $this->client = static::createClient();
        $user = static::createUser();
        static::authenticate($this->client, $user);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $currency = $entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']) ?? new Currency('EUR', 'Euro', '€', 2);
        $entityManager->persist($currency);
        $checking = new Account($user, AccountType::Asset, 'Checking');
        $checking->setAssetKind(AssetAccountKind::Checking);
        $checking->setCurrency($currency);
        $shop = new Account($user, AccountType::Expense, 'Esselunga');
        $groceries = new Category($user, 'Spesa');
        foreach ([$checking, $shop, $groceries] as $entity) {
            $entityManager->persist($entity);
        }
        $before = new Transaction($user, $checking, $shop, new \DateTimeImmutable('2026-10-12T18:00:00'), '61.2000', 'EUR');
        $before->setDescription('POS 12/10 ESSELUNGA MILANO CARTA *1234');
        $before->setCategory($groceries);
        $entityManager->persist($before);
        $cash = new Transaction($user, $checking, null, new \DateTimeImmutable('2026-10-20T09:00:00'), '30.0000', 'EUR');
        $cash->setDescription('Prelievo');
        $entityManager->persist($cash);
        $entityManager->flush();
        $this->checking = (string) $checking->getId();
        $this->shop = (string) $shop->getId();
        $this->groceries = (string) $groceries->getId();
    }

    public function testThePreviewSaysWhatEachRowLikelyIs(): void
    {
        $this->post('/api/recurring-transactions', [
            'description' => 'SIM', 'amount' => '4.99', 'startDate' => '2026-01-08', 'timezone' => 'Europe/Rome',
            'sourceAccountId' => $this->checking, 'newDestinationAccountName' => 'Iliad',
        ]);

        $preview = $this->post('/api/import/preview', ['rows' => [
            ['date' => '2026-10-21', 'amount' => '-30', 'accountId' => $this->checking, 'description' => 'PRELIEVO BANCOMAT'],
            ['date' => '2026-10-25', 'amount' => '-45.10', 'accountId' => $this->checking, 'description' => 'POS 25/10 ESSELUNGA MILANO CARTA *1234'],
            ['date' => '2026-11-08', 'amount' => '-4.99', 'accountId' => $this->checking, 'description' => 'ADDEBITO ILIAD'],
            ['date' => '2026-10-27', 'amount' => '1500', 'accountId' => $this->checking, 'description' => 'Bonifico'],
            // On an account the import will create: nothing to be a duplicate of, suggestions still.
            ['date' => '2026-10-20', 'amount' => '-30', 'description' => 'POS 20/10 ESSELUNGA MILANO CARTA *5555'],
        ]]);
        $this->assertResponseIsSuccessful();

        $this->assertNotNull($preview[0]['duplicate'], 'the 30 taken out a day before');
        $this->assertSame(['id' => $this->shop, 'name' => 'Esselunga', 'type' => 'expense'], $preview[1]['suggestion']['account']);
        $this->assertSame($this->groceries, $preview[1]['suggestion']['categoryId']);
        $this->assertSame('SIM', $preview[2]['recurring']['description']);
        $this->assertSame('2026-11-08', $preview[2]['recurring']['day']);
        $this->assertNull($preview[3]['duplicate']);
        $this->assertNull($preview[3]['suggestion']);
        $this->assertNull($preview[4]['duplicate']);
        $this->assertSame($this->groceries, $preview[4]['suggestion']['categoryId']);
    }

    public function testAnotherUsersAccountCantBePreviewed(): void
    {
        $other = static::createUser();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $theirs = new Account($entityManager->find(User::class, $other->getId()), AccountType::Asset, 'Theirs');
        $entityManager->persist($theirs);
        $entityManager->flush();

        $this->post('/api/import/preview', ['rows' => [['date' => '2026-10-21', 'amount' => '-30', 'accountId' => (string) $theirs->getId()]]]);
        $this->assertResponseStatusCodeSame(404);
    }

    /** A day far off would make the preview go through every recurring day up to it. */
    public function testARowDayMustBeWithinTheSupportedYears(): void
    {
        $preview = $this->post('/api/import/preview', ['rows' => [
            ['date' => '9999-12-31', 'amount' => '-1', 'accountId' => $this->checking, 'description' => 'Far off'],
        ]]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('invalid_date', array_column($preview['violations'], 'code', 'propertyPath')['rows[0][date]']);
    }

    public function testRowsAreImportedAllOrNone(): void
    {
        $row = fn (string $amount, string $day) => ['transaction' => [
            'description' => 'Esselunga', 'amount' => $amount, 'occurredAt' => $day.'T12:00:00', 'timezone' => 'Europe/Rome',
            'sourceAccountId' => $this->checking, 'destinationAccountId' => $this->shop,
        ]];

        $this->post('/api/import/commit', ['fileName' => 'a.csv', 'rows' => [
            $row('10', '2026-10-25'),
            ['transaction' => ['description' => 'New', 'amount' => '-1', 'occurredAt' => '2026-10-26T12:00:00', 'sourceAccountId' => $this->checking, 'newDestinationAccountName' => 'Brand new', 'newCategoryName' => 'Brand new']],
        ]]);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(2, $this->get('/api/transactions?kind=cash')['total'], 'nothing of a file with a wrong row');
        $this->assertNotContains('Brand new', array_column($this->get('/api/accounts/expense?pageSize=-1')['items'], 'name'), 'not even the payee it named');

        $batch = $this->post('/api/import/commit', ['fileName' => 'a.csv', 'rows' => [$row('10', '2026-10-25'), $row('12', '2026-10-26')]]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame(2, $batch['transactions']);
        $this->assertSame(4, $this->get('/api/transactions?kind=cash')['total']);
    }

    /**
     * A file of several accounts (a Firefly export): an account not in
     * FireFunds yet is created with its opening balance, payees, categories
     * and tags named by many rows are created once, a reconciliation becomes
     * a balance adjustment.
     */
    public function testAFileOfSeveralAccountsCreatesWhatItNames(): void
    {
        $row = fn (array $transaction, array $keys = []) => ['transaction' => $transaction + ['occurredAt' => '2024-01-10T08:00:00', 'timezone' => 'Europe/Rome']] + $keys;
        $batch = $this->post('/api/import/commit', [
            'fileName' => 'firefly.csv',
            'accounts' => [['key' => 'a1', 'name' => 'Banca', 'currencyCode' => 'EUR', 'openingBalance' => '100', 'openingBalanceDate' => '2024-01-01']],
            'rows' => [
                $row(['description' => 'Stipendio', 'amount' => '1500', 'newSourceAccountName' => 'Datore', 'newCategoryName' => 'Stipendio'], ['destinationAccountKey' => 'a1']),
                $row(['description' => 'Spesa', 'amount' => '20', 'newDestinationAccountName' => 'Esselunga', 'newCategoryName' => 'spesa', 'newTagNames' => ['Casa']], ['sourceAccountKey' => 'a1']),
                $row(['description' => 'Spesa', 'amount' => '30', 'newDestinationAccountName' => 'Nuovo negozio', 'newCategoryName' => 'Nuova', 'newTagNames' => ['Casa', 'Cibo']], ['sourceAccountKey' => 'a1']),
                $row(['description' => 'Spesa', 'amount' => '5', 'newDestinationAccountName' => 'nuovo negozio', 'newCategoryName' => 'nuova', 'newTagNames' => ['casa']], ['sourceAccountKey' => 'a1']),
                $row(['description' => 'Giroconto', 'amount' => '200', 'destinationAccountId' => $this->checking], ['sourceAccountKey' => 'a1']),
                $row(['description' => 'Regalo', 'amount' => '10'], ['sourceAccountKey' => 'a1']),
            ],
            'adjustments' => [['accountKey' => 'a1', 'date' => '2024-02-01', 'amount' => '-0.50', 'description' => 'Riconciliazione']],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame(6, $batch['transactions']);
        $this->assertSame(1, $batch['adjustments']);
        // Banca, Datore, Nuovo negozio (Esselunga was there); Stipendio and Nuova (Spesa was there); Casa and Cibo.
        $this->assertSame([3, 2, 2], [$batch['createdAccounts'], $batch['createdCategories'], $batch['createdTags']]);

        $banca = array_values(array_filter($this->get('/api/accounts/asset?pageSize=-1')['items'], fn (array $a) => $a['name'] === 'Banca'))[0];
        $this->assertSame('EUR', $banca['currencyCode']);
        // 100 + 1500 - 20 - 30 - 5 - 200 - 10 - 0.50
        $this->assertEqualsWithDelta(1334.5, (float) $banca['currentBalance'], 0.001);
        $this->assertSame(1, \count(array_filter($this->get('/api/accounts/expense?pageSize=-1')['items'], fn (array $a) => mb_strtolower($a['name']) === 'nuovo negozio')));
    }

    public function testAnImportIsUndoneWithWhatItCreatedAndNothingElseUses(): void
    {
        $batch = $this->post('/api/import/commit', [
            'fileName' => 'firefly.csv',
            'accounts' => [['key' => 'a1', 'name' => 'Banca', 'currencyCode' => 'EUR'], ['key' => 'a2', 'name' => 'Carta', 'currencyCode' => 'EUR']],
            'rows' => [
                ['transaction' => ['description' => 'Cena', 'amount' => '40', 'occurredAt' => '2024-01-10T20:00:00', 'newDestinationAccountName' => 'Trattoria', 'newCategoryName' => 'Ristoranti'], 'sourceAccountKey' => 'a1'],
                ['transaction' => ['description' => 'Spesa', 'amount' => '20', 'occurredAt' => '2024-01-11T20:00:00', 'destinationAccountId' => $this->shop, 'categoryId' => $this->groceries], 'sourceAccountKey' => 'a2'],
            ],
        ]);
        $this->assertResponseStatusCodeSame(201);
        // Since: something of the user's own on Carta, which must stay.
        $this->post('/api/transactions', ['description' => 'Caffè', 'amount' => '1', 'occurredAt' => '2026-10-27T09:00:00', 'timezone' => 'Europe/Rome', 'sourceAccountId' => $this->accountId('Carta')]);
        $this->assertResponseStatusCodeSame(201);

        $undone = $this->post("/api/import/batches/{$batch['id']}/undo", []);
        $this->assertResponseIsSuccessful();
        $this->assertSame(['transactions' => 2, 'adjustments' => 0, 'accounts' => 2, 'categories' => 1, 'tags' => 0], $undone);

        $assets = array_column($this->get('/api/accounts/asset?pageSize=-1')['items'], 'name');
        $this->assertNotContains('Banca', $assets);
        $this->assertContains('Carta', $assets);
        $this->assertNotContains('Trattoria', array_column($this->get('/api/accounts/expense?pageSize=-1')['items'], 'name'));
        $this->assertContains('Esselunga', array_column($this->get('/api/accounts/expense?pageSize=-1')['items'], 'name'), 'there before the import');
        $this->assertSame(3, $this->get('/api/transactions?kind=cash')['total']);
        $this->assertNotNull($this->get('/api/import/batches')[0]['undoneAt']);

        $again = $this->post("/api/import/batches/{$batch['id']}/undo", []);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('import_already_undone', $again['code']);
    }

    private function accountId(string $name): string
    {
        $match = array_values(array_filter($this->get('/api/accounts/asset?pageSize=-1')['items'], fn (array $a) => $a['name'] === $name));

        return $match[0]['id'];
    }

    public function testAPresetIsSavedForTheBanksNextFile(): void
    {
        $preset = $this->post('/api/import/presets', [
            'name' => 'Intesa', 'accountId' => $this->checking,
            'settings' => ['delimiter' => ';', 'dateFormat' => 'dd/MM/yyyy', 'amount' => ['mode' => 'single', 'column' => 3]],
            'headers' => ['Data', 'Operazione', 'Dettagli', 'Importo'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame([$preset['id']], array_column($this->get('/api/import/presets'), 'id'));

        $this->client->request('DELETE', "/api/import/presets/{$preset['id']}");
        $this->assertSame([], $this->get('/api/import/presets'));
    }

    public function testADescriptionIsKeyedWithoutWhatChangesEveryTime(): void
    {
        $this->assertSame(DescriptionHistory::key('POS 12/10 ESSELUNGA MILANO CARTA *1234'), DescriptionHistory::key('pos 03/11 Esselunga Milano carta *9876'));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<mixed>
     */
    private function post(string $url, array $body): array
    {
        $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($body));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @return array<mixed> */
    private function get(string $url): array
    {
        $this->client->request('GET', $url);
        $this->assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
