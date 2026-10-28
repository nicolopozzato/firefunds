<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\Category;
use App\Entity\Currency;
use App\Entity\Tag;
use App\Entity\Transaction;
use App\Entity\User;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * What a category's or a tag's page reads: its transactions (any money
 * kind), what was spent and received month by month, and in the lists the
 * day of the latest transaction.
 */
class ClassificationPageTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private User $user;
    private Category $category;
    private Tag $tag;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = static::createUser();
        static::authenticate($this->client, $this->user);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $currency = $entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']);
        if ($currency === null) {
            $currency = new Currency('EUR', 'Euro', '€', 2);
            $entityManager->persist($currency);
        }

        $checking = new Account($this->user, AccountType::Asset, 'Checking');
        $checking->setAssetKind(AssetAccountKind::Checking);
        $checking->setCurrency($currency);
        $shop = new Account($this->user, AccountType::Expense, 'Shop');
        $employer = new Account($this->user, AccountType::Revenue, 'Employer');
        $this->category = new Category($this->user, 'Holiday');
        $this->tag = new Tag($this->user, 'Sicily');
        foreach ([$checking, $shop, $employer, $this->category, $this->tag] as $entity) {
            $entityManager->persist($entity);
        }

        $add = function (Account $source, Account $destination, string $day, string $amount, bool $tagged) use ($entityManager): void {
            $transaction = new Transaction($this->user, $source, $destination, new \DateTimeImmutable($day.'T10:00:00'), $amount, 'EUR');
            $transaction->setDescription('Test');
            $transaction->setCategory($this->category);
            if ($tagged) {
                $transaction->addTag($this->tag);
            }
            $entityManager->persist($transaction);
        };
        $add($checking, $shop, '2026-08-10', '100.0000', true);
        $add($checking, $shop, '2026-09-05', '40.0000', false);
        $add($employer, $checking, '2026-09-20', '25.0000', true);
        $entityManager->flush();
    }

    public function testMonthsAddUpWhatWasSpentAndReceived(): void
    {
        $months = $this->get("/api/categories/{$this->category->getId()}/months?from=2026-08-01&to=2026-09-30");

        $this->assertSame([
            ['month' => '2026-08', 'expense' => '100.0000', 'income' => '0.0000'],
            ['month' => '2026-09', 'expense' => '40.0000', 'income' => '25.0000'],
        ], $months);

        $tagMonths = $this->get("/api/tags/{$this->tag->getId()}/months?from=2026-08-01&to=2026-09-30");
        $this->assertSame(['100.0000', '0.0000'], array_column($tagMonths, 'expense'));
    }

    public function testTheListOfEveryMoneyKindNarrowsToATagOrACategory(): void
    {
        $byTag = $this->get("/api/transactions?kind=cash&tagId={$this->tag->getId()}");
        $this->assertSame(2, $byTag['total'], 'a withdrawal and a deposit');

        $byCategory = $this->get("/api/transactions?kind=cash&categoryId={$this->category->getId()}");
        $this->assertSame(3, $byCategory['total']);
    }

    public function testListsSayTheDayOfTheLatestTransaction(): void
    {
        $categories = $this->get('/api/categories');
        $this->assertSame('2026-09-20', $categories['items'][0]['lastTransactionAt']);

        $tags = $this->get('/api/tags');
        $this->assertSame('2026-09-20', $tags['items'][0]['lastTransactionAt']);
    }

    public function testDescriptionSuggestionsOfferTheLatestOfEachDescription(): void
    {
        $suggestions = $this->get('/api/transactions/suggestions?q=tes');
        $this->assertCount(1, $suggestions, 'one per description');
        $this->assertSame('2026-09-20T10:00:00', $suggestions[0]['occurredAt']);
        $this->assertSame((string) $this->category->getId(), $suggestions[0]['categoryId']);

        $this->assertSame([], $this->get('/api/transactions/suggestions?q=t'), 'too short');
        $this->assertSame([], $this->get('/api/transactions/suggestions?q=%25'), 'a wildcard is just a character');
    }

    public function testSeveralTransactionsGetATagOrLoseTheirCategoryAtOnce(): void
    {
        $ids = array_column($this->get("/api/transactions?kind=cash&categoryId={$this->category->getId()}")['items'], 'id');
        $other = new Tag($this->user, 'Work');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($other);
        $entityManager->flush();

        $this->post('/api/transactions/bulk', ['ids' => $ids, 'action' => 'tag', 'tagId' => (string) $other->getId()]);
        $this->assertResponseIsSuccessful();
        $this->assertSame(3, $this->get("/api/transactions?kind=cash&tagId={$other->getId()}")['total']);

        $this->post('/api/transactions/bulk', ['ids' => $ids, 'action' => 'category', 'categoryId' => null]);
        $this->assertSame(0, $this->get("/api/transactions?kind=cash&categoryId={$this->category->getId()}")['total']);

        $this->client->request('GET', "/api/transactions/{$ids[0]}/audit-log");
        $this->assertContains('updated', array_column(json_decode((string) $this->client->getResponse()->getContent(), true), 'action'));
    }

    public function testABulkChangeWithAnIdOfAnotherUserChangesNothing(): void
    {
        $ids = array_column($this->get("/api/transactions?kind=cash&categoryId={$this->category->getId()}")['items'], 'id');

        $this->post('/api/transactions/bulk', ['ids' => [...$ids, '01a1834f-0000-7000-8000-000000000000'], 'action' => 'delete']);
        $this->assertResponseStatusCodeSame(404);
        $this->assertSame(3, $this->get("/api/transactions?kind=cash&categoryId={$this->category->getId()}")['total']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $url, array $body): void
    {
        $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($body));
    }

    /**
     * @return array<mixed>
     */
    private function get(string $url): array
    {
        $this->client->request('GET', $url);
        $this->assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
