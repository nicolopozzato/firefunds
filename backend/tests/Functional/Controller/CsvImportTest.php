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

        $preview = $this->post('/api/import/preview', ['accountId' => $this->checking, 'rows' => [
            ['date' => '2026-10-21', 'amount' => '-30', 'description' => 'PRELIEVO BANCOMAT'],
            ['date' => '2026-10-25', 'amount' => '-45.10', 'description' => 'POS 25/10 ESSELUNGA MILANO CARTA *1234'],
            ['date' => '2026-11-08', 'amount' => '-4.99', 'description' => 'ADDEBITO ILIAD'],
            ['date' => '2026-10-27', 'amount' => '1500', 'description' => 'Bonifico'],
        ]]);
        $this->assertResponseIsSuccessful();

        $this->assertNotNull($preview[0]['duplicate'], 'the 30 taken out a day before');
        $this->assertSame(['id' => $this->shop, 'name' => 'Esselunga', 'type' => 'expense'], $preview[1]['suggestion']['account']);
        $this->assertSame($this->groceries, $preview[1]['suggestion']['categoryId']);
        $this->assertSame('SIM', $preview[2]['recurring']['description']);
        $this->assertSame('2026-11-08', $preview[2]['recurring']['day']);
        $this->assertNull($preview[3]['duplicate']);
        $this->assertNull($preview[3]['suggestion']);
    }

    public function testRowsAreImportedAllOrNone(): void
    {
        $row = fn (string $amount, string $day) => ['transaction' => [
            'description' => 'Esselunga', 'amount' => $amount, 'occurredAt' => $day.'T12:00:00', 'timezone' => 'Europe/Rome',
            'sourceAccountId' => $this->checking, 'destinationAccountId' => $this->shop,
        ]];

        $this->post('/api/import/commit', ['rows' => [$row('10', '2026-10-25'), $row('-1', '2026-10-26')]]);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(2, $this->get('/api/transactions?kind=cash')['total'], 'nothing of a file with a wrong row');

        $this->assertSame(['imported' => 2], $this->post('/api/import/commit', ['rows' => [$row('10', '2026-10-25'), $row('12', '2026-10-26')]]));
        $this->assertSame(4, $this->get('/api/transactions?kind=cash')['total']);
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
