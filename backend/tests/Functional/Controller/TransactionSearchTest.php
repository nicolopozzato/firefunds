<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\Category;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentInstrumentType;
use App\Entity\InvestmentTransaction;
use App\Entity\InvestmentTransactionType;
use App\Entity\Tag;
use App\Entity\Transaction;
use App\Entity\User;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * GET /api/transactions?q=…, /api/transactions/totals?q=… and
 * /api/search/suggestions?q=… : the search box.
 */
class TransactionSearchTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = static::createUser();
        static::authenticate($this->client, $this->user);
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $bank = $this->account(AccountType::Asset, 'Banca', AssetAccountKind::Checking);
        $broker = $this->account(AccountType::Asset, 'Broker', AssetAccountKind::Investment);
        $kfc = $this->account(AccountType::Expense, 'KFC Centro');
        $bar = $this->account(AccountType::Expense, 'Bar');
        $amazon = $this->account(AccountType::Expense, 'Amazon');
        $salary = $this->account(AccountType::Revenue, 'Datore di lavoro');
        $restaurants = new Category($this->user, 'Ristoranti');
        $holiday = new Tag($this->user, 'Vacanza');
        $instrument = new InvestmentInstrument($this->user, 'Vanguard FTSE All-World', InvestmentInstrumentType::Etf);
        $instrument->setTicker('VWCE')->setIsin('IE00BK5BQT80');
        foreach ([$restaurants, $holiday, $instrument] as $entity) {
            $this->entityManager->persist($entity);
        }

        $this->transaction($bank, $kfc, '2026-01-10 12:00', '9.5000', 'Pranzo')->setNotes('panino e patatine');
        $this->transaction($bank, $kfc, '2026-02-10 20:00', '21.0000', 'Cena')->setCategory($restaurants)->addTag($holiday);
        $this->transaction($bank, $bar, '2026-01-11 08:00', '1.2000', 'Caffè Bar Roma');
        $this->transaction($bank, $amazon, '2026-01-01 10:00', '100.0000', 'Amazon Telefono 1/3');
        $this->transaction($bank, $amazon, '2026-02-01 10:00', '100.0000', 'Amazon Telefono 2/3');
        $this->transaction($bank, $amazon, '2026-01-05 10:00', '15.0000', 'Amazon Libro');
        $this->transaction($bank, null, '2026-01-06 10:00', '50.0000', 'Regalo 50% a_b');
        $this->transaction($salary, $bank, '2026-01-27 09:00', '2000.0000', 'Stipendio');
        $this->transaction($bank, $broker, '2026-01-15 09:00', '500.0000', 'Versamento');
        $this->transaction($bank, $kfc, '2026-01-20 12:00', '8.0000', 'Pranzo cancellato')->markDeleted();
        $buy = new InvestmentTransaction($this->user, $broker, $instrument, InvestmentTransactionType::Buy, '2', '100.0000', new \DateTimeImmutable('2026-01-16 10:00'));
        $buy->setDescription('Acquisto ETF');
        $this->entityManager->persist($buy);

        $someoneElse = static::createUser();
        $theirBank = new Account($someoneElse, AccountType::Asset, 'Banca');
        $theirKfc = new Account($someoneElse, AccountType::Expense, 'KFC');
        $theirs = new Transaction($someoneElse, $theirBank, $theirKfc, new \DateTimeImmutable('2026-01-10 12:00'), '5.0000', 'EUR');
        $theirs->setDescription('Pranzo altrui');
        foreach ([$theirBank, $theirKfc, $theirs] as $entity) {
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();
    }

    public function testFindsTheDestinationAccountsName(): void
    {
        $this->assertSame(['Cena', 'Pranzo'], $this->search('kfc'));
    }

    public function testEveryWordMustMatchSomewhere(): void
    {
        $this->assertSame(['Pranzo'], $this->search('kfc panino'));
        $this->assertSame([], $this->search('kfc libro'));
    }

    public function testSearchesNotesCategoryAndTags(): void
    {
        $this->assertSame(['Pranzo'], $this->search('patatine'));
        $this->assertSame(['Cena'], $this->search('ristoranti'));
        $this->assertSame(['Cena'], $this->search('vacanza'));
    }

    public function testIgnoresCaseAndAccents(): void
    {
        $this->assertSame(['Caffè Bar Roma'], $this->search('CAFFE'));
    }

    public function testMatchesPartOfAWord(): void
    {
        $this->assertSame(['Amazon Telefono 2/3', 'Amazon Telefono 1/3'], $this->search('/3'));
        $this->assertSame(['Amazon Telefono 1/3'], $this->search('1/3'));
    }

    public function testPhraseAndExclusion(): void
    {
        $this->assertSame(['Caffè Bar Roma'], $this->search('roma bar'));
        $this->assertSame([], $this->search('"roma bar"'));
        $this->assertSame(['Amazon Telefono 2/3', 'Amazon Telefono 1/3'], $this->search('amazon -libro'));
    }

    public function testLikeWildcardsAreSearchedAsTyped(): void
    {
        $this->assertSame(['Regalo 50% a_b'], $this->search('%'));
        $this->assertSame(['Regalo 50% a_b'], $this->search('a_b'));
    }

    public function testFindsTradesByInstrumentAndAccount(): void
    {
        $this->assertSame(['Acquisto ETF'], $this->search('vwce'));
        $this->assertSame(['Acquisto ETF'], $this->search('ie00bk5'));
        $this->assertSame(['Acquisto ETF', 'Versamento'], $this->search('broker'));
    }

    public function testKindAndPeriodNarrowTheSearch(): void
    {
        $this->assertSame(['Versamento'], $this->search('broker', ['kind' => 'transfer']));
        $this->assertSame(['Acquisto ETF'], $this->search('broker', ['kind' => 'trade']));
        $this->assertSame(['Cena', 'Pranzo'], $this->search('kfc', ['kind' => 'withdrawal']));
        $this->assertSame([], $this->search('kfc', ['kind' => 'deposit']));
        $this->assertSame(['Pranzo'], $this->search('kfc', ['from' => '2026-01-01', 'to' => '2026-01-31']));
    }

    public function testEmptySearchListsEverything(): void
    {
        $this->client->request('GET', '/api/transactions', ['q' => '  ', 'pageSize' => 50]);

        $this->assertResponseIsSuccessful();
        $this->assertSame(10, $this->json()['total']);
    }

    public function testTotalsAddUpEveryMatchPerKind(): void
    {
        $this->client->request('GET', '/api/transactions/totals', ['q' => 'amazon']);
        $this->assertResponseIsSuccessful();
        $totals = $this->json();
        $this->assertEquals(215, $totals['withdrawal']);
        $this->assertEquals(0, $totals['deposit']);

        $this->client->request('GET', '/api/transactions/totals', ['q' => 'broker']);
        $totals = $this->json();
        $this->assertEquals(500, $totals['transfer']);
        $this->assertEquals(200, $totals['investmentBuy']);
        $this->assertEquals(0, $totals['withdrawal']);
    }

    public function testSuggestsAccountsAndInstruments(): void
    {
        $this->client->request('GET', '/api/search/suggestions', ['q' => 'kfc']);
        $this->assertResponseIsSuccessful();
        $suggestions = $this->json();
        $this->assertSame([['name' => 'KFC Centro', 'type' => 'expense']], array_map(
            static fn (array $account) => ['name' => $account['name'], 'type' => $account['type']],
            $suggestions['accounts'],
        ));
        $this->assertSame([], $suggestions['instruments']);

        $this->client->request('GET', '/api/search/suggestions', ['q' => 'vwce']);
        $this->assertSame(['Vanguard FTSE All-World'], array_column($this->json()['instruments'], 'name'));
    }

    /**
     * Descriptions of the transactions found, newest first.
     *
     * @param array<string, string> $filters
     * @return string[]
     */
    private function search(string $query, array $filters = []): array
    {
        $this->client->request('GET', '/api/transactions', ['q' => $query, 'pageSize' => 50] + $filters);
        $this->assertResponseIsSuccessful();

        return array_column($this->json()['items'], 'description');
    }

    private function json(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function account(AccountType $type, string $name, ?AssetAccountKind $kind = null): Account
    {
        $account = new Account($this->user, $type, $name);
        if ($kind !== null) {
            $account->setAssetKind($kind);
        }
        $this->entityManager->persist($account);

        return $account;
    }

    private function transaction(?Account $source, ?Account $destination, string $at, string $amount, string $description): Transaction
    {
        $transaction = new Transaction($this->user, $source, $destination, new \DateTimeImmutable($at), $amount, 'EUR');
        $transaction->setDescription($description);
        $this->entityManager->persist($transaction);

        return $transaction;
    }
}
