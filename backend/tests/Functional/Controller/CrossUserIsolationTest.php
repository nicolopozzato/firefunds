<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\Category;
use App\Entity\Currency;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentInstrumentType;
use App\Entity\InvestmentTransaction;
use App\Entity\InvestmentTransactionType;
use App\Entity\Tag;
use App\Entity\Transaction;
use App\Entity\User;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * One user can't read, change, delete or point to another user's data:
 * every route that takes an id, every id inside a payload and every id filter
 * answers "not found" for someone else's, exactly as for one that doesn't
 * exist (no hint that it does).
 */
class CrossUserIsolationTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    /** @var array<string, string> the other user's ids, by kind */
    private array $theirs = [];
    private string $myWallet;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $eur = $this->eur();

        $other = static::createUser();
        $bank = $this->account($other, AccountType::Asset, 'Their bank', $eur, AssetAccountKind::Checking);
        $shop = $this->account($other, AccountType::Expense, 'Their shop');
        $broker = $this->account($other, AccountType::Asset, 'Their broker', $eur, AssetAccountKind::Investment);
        $category = new Category($other, 'Their category');
        $tag = new Tag($other, 'Their tag');
        $instrument = new InvestmentInstrument($other, 'Their ETF', InvestmentInstrumentType::Etf);
        $this->entityManager->persist($category);
        $this->entityManager->persist($tag);
        $this->entityManager->persist($instrument);
        $transaction = new Transaction($other, $bank, $shop, new \DateTimeImmutable('2026-10-01 10:00', new \DateTimeZone('Europe/Rome')), '10.0000', 'EUR');
        $transaction->setCategory($category);
        $this->entityManager->persist($transaction);
        $trade = new InvestmentTransaction($other, $broker, $instrument, InvestmentTransactionType::Buy, '1', '100', new \DateTimeImmutable('2026-10-02 10:00', new \DateTimeZone('Europe/Rome')));
        $this->entityManager->persist($trade);

        $me = static::createUser();
        $this->myWallet = (string) $this->account($me, AccountType::Asset, 'My wallet', $eur, AssetAccountKind::Checking)->getId();
        $this->entityManager->flush();

        $this->theirs = [
            'account' => (string) $bank->getId(),
            'shop' => (string) $shop->getId(),
            'broker' => (string) $broker->getId(),
            'category' => (string) $category->getId(),
            'tag' => (string) $tag->getId(),
            'instrument' => (string) $instrument->getId(),
            'transaction' => (string) $transaction->getId(),
            'trade' => (string) $trade->getId(),
        ];

        static::authenticate($this->client, $me);
    }

    /**
     * Routes that take an id in the path or a filter: 404.
     *
     * @return iterable<string, array{string, string, ?array<string, mixed>}>
     */
    public static function routes(): iterable
    {
        foreach (['', '/balance-history', '/value-history', '/distributions', '/positions', '/deletion-preview'] as $suffix) {
            yield "GET account$suffix" => ['GET', '/api/accounts/{account}'.$suffix, null];
        }
        yield 'PUT account' => ['PUT', '/api/accounts/{account}', ['name' => 'Mine now', 'currencyCode' => 'EUR']];
        yield 'move account' => ['PUT', '/api/accounts/{account}/position', ['afterId' => '{myWallet}']];
        yield 'DELETE account' => ['DELETE', '/api/accounts/{account}?deleteTransactions=1', null];

        yield 'GET transaction' => ['GET', '/api/transactions/{transaction}', null];
        yield 'transaction history' => ['GET', '/api/transactions/{transaction}/audit-log', null];
        yield 'PUT transaction' => ['PUT', '/api/transactions/{transaction}', ['description' => 'x', 'sourceAccountId' => '{myWallet}', 'destinationAccountId' => null, 'occurredAt' => '2026-10-01T10:00:00', 'amount' => '1']];
        yield 'DELETE transaction' => ['DELETE', '/api/transactions/{transaction}', null];

        yield 'GET trade' => ['GET', '/api/investment-transactions/{trade}', null];
        yield 'trade history' => ['GET', '/api/investment-transactions/{trade}/audit-log', null];
        yield 'PUT trade' => ['PUT', '/api/investment-transactions/{trade}', ['accountId' => '{broker}', 'instrumentId' => '{instrument}', 'type' => 'buy', 'quantity' => '1', 'unitPrice' => '1', 'occurredAt' => '2026-10-01T10:00:00']];
        yield 'DELETE trade' => ['DELETE', '/api/investment-transactions/{trade}', null];

        foreach (['', '/prices', '/live-price', '/deletion-preview'] as $suffix) {
            yield "GET instrument$suffix" => ['GET', '/api/investment-instruments/{instrument}'.$suffix, null];
        }
        yield 'import prices' => ['POST', '/api/investment-instruments/{instrument}/prices/import', ['from' => '2026-09-01', 'to' => '2026-09-30']];
        yield 'PUT instrument' => ['PUT', '/api/investment-instruments/{instrument}', ['name' => 'Mine now', 'type' => 'etf']];
        yield 'DELETE instrument' => ['DELETE', '/api/investment-instruments/{instrument}?deleteTransactions=1', null];

        foreach (['category' => 'categories', 'tag' => 'tags'] as $kind => $path) {
            yield "GET $kind" => ['GET', "/api/$path/{{$kind}}", null];
            yield "$kind deletion preview" => ['GET', "/api/$path/{{$kind}}/deletion-preview", null];
            yield "PUT $kind" => ['PUT', "/api/$path/{{$kind}}", ['name' => 'Mine now']];
            yield "DELETE $kind" => ['DELETE', "/api/$path/{{$kind}}", null];
        }

        yield 'transactions of their account' => ['GET', '/api/transactions?accountId={account}', null];
        yield 'transactions of their category' => ['GET', '/api/transactions?categoryId={category}', null];
        yield 'trades of their broker' => ['GET', '/api/investment-transactions?accountId={broker}', null];
        yield 'trades of their instrument' => ['GET', '/api/investment-transactions?instrumentId={instrument}', null];
    }

    /**
     * Ids inside a payload: refused with the same code as an id that doesn't
     * exist (the request is wrong, the resource is not the URL's: 400).
     *
     * @return iterable<string, array{string, string, array<string, mixed>, string}>
     */
    public static function payloads(): iterable
    {
        $transaction = ['description' => 'x', 'sourceAccountId' => '{myWallet}', 'destinationAccountId' => null, 'occurredAt' => '2026-10-01T10:00:00', 'amount' => '1'];
        yield 'their account as destination' => ['POST', '/api/transactions', ['destinationAccountId' => '{shop}'] + $transaction, 'account_not_found'];
        yield 'their category' => ['POST', '/api/transactions', ['categoryId' => '{category}'] + $transaction, 'category_not_found'];
        yield 'their tag' => ['POST', '/api/transactions', ['tagIds' => ['{tag}']] + $transaction, 'tag_not_found'];
        yield 'their broker for a trade' => ['POST', '/api/investment-transactions', ['accountId' => '{broker}', 'instrumentId' => '{instrument}', 'type' => 'buy', 'quantity' => '1', 'unitPrice' => '1', 'occurredAt' => '2026-10-01T10:00:00'], 'account_not_found'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('payloads')]
    public function testAnotherUsersIdInAPayloadIsRefused(string $method, string $uri, array $body, string $code): void
    {
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($this->fillAll($body)));

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame($code, json_decode((string) $this->client->getResponse()->getContent(), true)['code']);
    }

    /** Deleting an account that has transactions, moving them to someone else's. */
    public function testTheirAccountIsNoTransferTarget(): void
    {
        $this->client->request('POST', '/api/transactions', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'description' => 'Mine', 'sourceAccountId' => $this->myWallet, 'destinationAccountId' => null, 'occurredAt' => '2026-10-01T10:00:00', 'amount' => '5',
        ]));
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('DELETE', '/api/accounts/'.$this->myWallet.'?transferTo='.$this->theirs['account']);

        // As for a target that doesn't exist; and nothing was deleted.
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('transfer_target_invalid', json_decode((string) $this->client->getResponse()->getContent(), true)['code']);
        $this->client->request('GET', '/api/accounts/'.$this->myWallet);
        $this->assertResponseIsSuccessful();
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('routes')]
    public function testAnotherUsersIdIsNotFound(string $method, string $uri, ?array $body): void
    {
        $this->client->request(
            $method,
            $this->fill($uri),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            null === $body ? null : (string) json_encode($this->fillAll($body)),
        );

        $this->assertResponseStatusCodeSame(404, (string) $this->client->getResponse()->getContent());
    }

    public function testTheirDataIsUntouchedAndUnseen(): void
    {
        $this->client->request('GET', '/api/transactions?pageSize=-1');
        $listed = json_decode((string) $this->client->getResponse()->getContent(), true)['items'];
        $this->assertNotContains($this->theirs['transaction'], array_column($listed, 'id'));

        $this->client->request('GET', '/api/search/suggestions?q=Their');
        $this->assertSame([], array_merge(...array_values(json_decode((string) $this->client->getResponse()->getContent(), true))));
    }

    private function fill(string $text): string
    {
        return preg_replace_callback('/\{(\w+)\}/', fn (array $m) => 'myWallet' === $m[1] ? $this->myWallet : $this->theirs[$m[1]], $text);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function fillAll(array $body): array
    {
        array_walk_recursive($body, function (mixed &$value): void {
            if (\is_string($value)) {
                $value = $this->fill($value);
            }
        });

        return $body;
    }

    private function account(User $user, AccountType $type, string $name, ?Currency $currency = null, ?AssetAccountKind $kind = null): Account
    {
        $account = new Account($user, $type, $name);
        if (null !== $currency) {
            $account->setCurrency($currency);
        }
        if (null !== $kind) {
            $account->setAssetKind($kind);
        }
        $this->entityManager->persist($account);

        return $account;
    }

    private function eur(): Currency
    {
        $eur = $this->entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']);
        if (null === $eur) {
            $eur = new Currency('EUR', 'Euro', '€', 2);
            $this->entityManager->persist($eur);
        }

        return $eur;
    }
}
