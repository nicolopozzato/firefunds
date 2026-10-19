<?php

namespace App\Tests\Functional\Controller;

use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentInstrumentType;
use App\Entity\User;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * What the API refuses, with a code the frontend translates, instead of
 * storing it or failing with a 500: numbers that aren't numbers, dates that
 * don't exist or are relative, ids that aren't ids, and date ranges too long
 * to compute day by day.
 */
class InputValidationTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = static::createUser();
        static::authenticate($this->client, $this->user);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAnAmount(): iterable
    {
        yield 'NaN' => ['NaN'];
        yield 'letters' => ['abc'];
        yield 'exponent' => ['1e3'];
        yield 'five decimals' => ['1.12345'];
        yield 'too many digits' => ['1234567890123456'];
        yield 'comma' => ['12,50'];
    }

    /**
     * "NaN" used to be stored, and from then on the dashboard answered 500.
     */
    #[DataProvider('notAnAmount')]
    public function testATransactionAmountMustBeANumber(string $amount): void
    {
        $violations = $this->violations('POST', '/api/transactions', $this->transaction(['amount' => $amount]));

        $this->assertSame('invalid_number', $violations['amount']);
    }

    public function testAnAmountWithFourDecimalsIsAccepted(): void
    {
        $this->request('POST', '/api/transactions', $this->transaction(['amount' => '12.3456']));

        $this->assertResponseStatusCodeSame(201);
    }

    public function testOtherNumberFieldsMustBeNumbersToo(): void
    {
        $this->assertSame('invalid_number', $this->violations('POST', '/api/accounts/asset', [
            'type' => 'asset', 'name' => 'Cash', 'currencyCode' => 'EUR', 'assetKind' => 'checking', 'openingBalance' => 'NaN',
        ])['openingBalance']);

        $trade = $this->violations('POST', '/api/investment-transactions', [
            'accountId' => '01890000-0000-7000-8000-000000000000',
            'instrumentId' => '01890000-0000-7000-8000-000000000001',
            'type' => 'buy',
            'quantity' => '1.1234567',
            'unitPrice' => '10',
            'fees' => 'NaN',
            'occurredAt' => '2026-10-01T10:00:00Z',
        ]);
        $this->assertSame('invalid_number', $trade['quantity']);
        $this->assertSame('invalid_number', $trade['fees']);
    }

    public function testANegativeOpeningBalanceIsANumber(): void
    {
        $this->request('POST', '/api/accounts/asset', [
            'type' => 'asset', 'name' => 'Card', 'currencyCode' => 'EUR', 'assetKind' => 'checking', 'openingBalance' => '-120.50',
        ]);

        $this->assertResponseStatusCodeSame(201);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notADate(): iterable
    {
        yield 'relative' => ['+5000000 years'];
        yield 'words' => ['tomorrow'];
        yield 'no such day' => ['2026-02-31T10:00:00Z'];
        yield 'before 1970' => ['1969-12-31T10:00:00Z'];
        yield 'after 2100' => ['2101-01-01T10:00:00Z'];
    }

    #[DataProvider('notADate')]
    public function testATransactionDateMustExist(string $occurredAt): void
    {
        $violations = $this->violations('POST', '/api/transactions', $this->transaction(['occurredAt' => $occurredAt]));

        $this->assertSame('invalid_date', $violations['occurredAt']);
    }

    public function testAnOpeningBalanceDateMustExist(): void
    {
        $violations = $this->violations('POST', '/api/accounts/asset', [
            'type' => 'asset', 'name' => 'Cash', 'currencyCode' => 'EUR', 'assetKind' => 'checking', 'openingBalanceDate' => '+1 week',
        ]);

        $this->assertSame('invalid_date', $violations['openingBalanceDate']);
    }

    public function testIdsInAPayloadMustBeIds(): void
    {
        $violations = $this->violations('POST', '/api/transactions', $this->transaction([
            'sourceAccountId' => 'zzz',
            'categoryId' => 'not-an-id',
            'tagIds' => [12],
        ]));

        $this->assertSame('invalid_id', $violations['sourceAccountId']);
        $this->assertSame('invalid_id', $violations['categoryId']);
        $this->assertArrayHasKey('tagIds[0]', $violations);
    }

    public function testATransactionHasAtMostFiftyTags(): void
    {
        $tagIds = array_map(static fn (int $i): string => \sprintf('01890000-0000-7000-8000-%012d', $i), range(1, 51));

        $violations = $this->violations('POST', '/api/transactions', $this->transaction(['tagIds' => $tagIds]));

        $this->assertSame('too_many', $violations['tagIds']);
    }

    public function testTheSameAccountInAnotherCaseIsStillTheSameAccount(): void
    {
        $id = '01890000-0000-7000-8000-00000000abcd';

        $violations = $this->violations('POST', '/api/transactions', $this->transaction([
            'sourceAccountId' => $id,
            'destinationAccountId' => strtoupper($id),
        ]));

        $this->assertSame('transaction_accounts_same', $violations['destinationAccountId']);
    }

    public function testAFilterThatIsNotAnIdFindsNothing(): void
    {
        $this->request('GET', '/api/transactions?accountId=not-a-uuid');
        $this->assertResponseStatusCodeSame(404);

        $this->request('GET', '/api/investment-transactions?instrumentId=not-a-uuid');
        $this->assertResponseStatusCodeSame(404);
    }

    /** The offset used to overflow into a float, and the query to fail. */
    public function testAHugePageNumberIsJustAnEmptyPage(): void
    {
        $this->request('GET', '/api/transactions?page=99999999999999');

        $this->assertResponseIsSuccessful();
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function badRange(): iterable
    {
        yield 'before 1970' => ['1900-01-01', '2000-12-31', 'invalid_date_range'];
        yield 'relative' => ['2026-01-01', '+5000000 years', 'invalid_date_range'];
        yield 'no such day' => ['2026-02-30', '2026-03-31', 'invalid_date_range'];
        yield 'inverted' => ['2026-10-31', '2026-10-01', 'date_range_inverted'];
        yield 'over fifty years' => ['1990-01-01', '2041-01-01', 'date_range_too_long'];
    }

    /**
     * A day costs a row per account: 1900 to 2999 on one account used to
     * exhaust PHP's memory.
     */
    #[DataProvider('badRange')]
    public function testDayByDayEndpointsRefuseUnreasonableRanges(string $from, string $to, string $code): void
    {
        $account = $this->request('POST', '/api/accounts/asset', [
            'type' => 'asset', 'name' => 'Cash', 'currencyCode' => 'EUR', 'assetKind' => 'checking',
        ]);
        $query = http_build_query(['from' => $from, 'to' => $to]);

        foreach ([
            '/api/accounts/'.$account['id'].'/balance-history?'.$query,
            '/api/accounts/'.$account['id'].'/value-history?'.$query,
            '/api/dashboard?'.$query,
            '/api/investments/portfolio?'.$query,
            '/api/reports/income-expenses?'.$query,
        ] as $url) {
            $data = $this->request('GET', $url);
            $this->assertResponseStatusCodeSame(400, $url);
            $this->assertSame($code, $data['code'], $url);
        }
    }

    public function testFiftyYearsIsFine(): void
    {
        $this->request('GET', '/api/dashboard?from=1990-01-01&to=2039-12-31');

        $this->assertResponseIsSuccessful();
    }

    /**
     * Prices exist up to yesterday: a range entirely in the future has
     * nothing to import, and used to be accepted doing nothing.
     */
    public function testImportingPricesOnlyInTheFutureIsAnInvertedRange(): void
    {
        $instrument = new InvestmentInstrument($this->user, 'Fund', InvestmentInstrumentType::Etf);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($instrument);
        $entityManager->flush();

        $future = new \DateTimeImmutable('+1 month');
        $data = $this->request('POST', '/api/investment-instruments/'.$instrument->getId().'/prices/import', [
            'from' => $future->format('Y-m-d'),
            'to' => $future->modify('+1 month')->format('Y-m-d'),
        ]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('date_range_inverted', $data['code']);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function transaction(array $overrides = []): array
    {
        $wallet = $this->request('POST', '/api/accounts/asset', [
            'type' => 'asset', 'name' => 'Wallet '.uniqid(), 'currencyCode' => 'EUR', 'assetKind' => 'checking',
        ]);

        return $overrides + [
            'description' => 'Groceries',
            'sourceAccountId' => $wallet['id'],
            'destinationAccountId' => null,
            'occurredAt' => '2026-10-01T10:00:00Z',
            'amount' => '12.50',
        ];
    }

    /**
     * The validation codes of a refused request, by field.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, string>
     */
    private function violations(string $method, string $uri, array $body): array
    {
        $data = $this->request($method, $uri, $body);
        $this->assertResponseStatusCodeSame(422);

        return array_column($data['violations'], 'code', 'propertyPath');
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $uri, ?array $body = null): array
    {
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], null === $body ? null : json_encode($body));

        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }
}
