<?php

namespace App\Tests\Integration\Currency;

use App\Currency\Service\CurrencyConverter;
use App\Dashboard\Service\DashboardService;
use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\Currency;
use App\Entity\ExchangeRateOverride;
use App\Entity\ExchangeRateSource;
use App\Entity\Transaction;
use App\Entity\User;
use App\Report\Service\NetWorthReportService;
use App\Repository\ExchangeRateRepository;
use App\Repository\TransactionRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A user in euros with a dollar account: what adds accounts up is in euros, each
 * amount at its day's rate. 1 EUR = 1.25 USD on 1 and 2 October, 1 USD = 1 EUR
 * from the 3rd.
 *
 *   EUR account 1000, USD account 500 USD (400 EUR at the start: 1400 in all)
 *   1 Oct  -100 USD at 1.25 = -80 EUR   (expense)
 *   2 Oct   -50 EUR                     (expense)
 *   3 Oct  +125 USD at 1.00 = +125 EUR  (income)
 *   end: 950 EUR + 525 USD (= 525 EUR) = 1475
 */
class MultiCurrencyTotalsTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->user = new User();
        $this->user->setFirstName('Totals');
        $this->user->setLastName('Test');
        $this->user->setEmail(\sprintf('totals-%s@example.test', uniqid()));
        $this->user->setPassword('hashed');
        $this->user->setTimezone('Europe/Rome');
        $this->entityManager->persist($this->user);

        $checking = $this->asset('Conto', 'EUR', '1000.0000');
        $dollars = $this->asset('Conto USA', 'USD', '500.0000');
        $shop = $this->account('Shop', AccountType::Expense);
        $employer = $this->account('Employer', AccountType::Revenue);

        $this->money($dollars, $shop, '100.0000', 'USD', '2026-10-01 10:00');
        $this->money($checking, $shop, '50.0000', 'EUR', '2026-10-02 10:00');
        $this->money($employer, $dollars, '125.0000', 'USD', '2026-10-03 10:00');
        $this->entityManager->flush();

        $container->get(ExchangeRateRepository::class)->upsert('USD', [
            '2026-10-01' => ['1.25000000', ExchangeRateSource::Ecb],
            '2026-10-02' => ['1.25000000', ExchangeRateSource::Ecb],
            '2026-10-03' => ['1.00000000', ExchangeRateSource::Ecb],
        ]);
    }

    public function testTheDashboardAddsEverythingUpInTheUsersCurrency(): void
    {
        $summary = self::getContainer()->get(DashboardService::class)
            ->forUser($this->user, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-03'))
            ->summary;

        $this->assertSame('125.0000', $summary->income, '125 USD on the day the dollar was at par');
        $this->assertSame('130.0000', $summary->expenses, '100 USD at 1.25 = 80, and 50 EUR');
        $this->assertSame('1400.0000', $summary->netWorthStart);
        $this->assertSame('1475.0000', $summary->netWorthEnd);
        $this->assertSame('75.0000', $summary->netWorthChange);
    }

    public function testTheNetWorthChangeIsSplitInSavedMarketAndTheExchangeEffect(): void
    {
        $report = self::getContainer()->get(NetWorthReportService::class)->forUser($this->user, 2026, new \DateTimeImmutable('2026-10-31'));

        $october = 9;
        $this->assertSame('1475.0000', $report->total->values[$october]);
        $this->assertSame('-5.0000', $report->saved->values[$october], '125 - 130');
        $this->assertSame('0.0000', $report->market->values[$october]);
        // 500 USD held at the start gained 0.20 each (0.80 → 1.00) = +100; the 100 USD spent on the 1st, at 0.80 then,
        // is worth 1.00 at the end: -20.
        $this->assertSame('80.0000', $report->exchange->values[$october]);
        // 1475 - 1400 = 75 = saved -5 + market 0 + exchange 80 + nothing else.
        $this->assertSame(['USD'], array_map(static fn ($row) => $row->id, $report->rates));
        $this->assertSame('1.000000', $report->rates[0]->values[$october], '1 USD in euros at the end of October');
        $this->assertSame('USD', $report->cash[1]->currencyCode);
        $this->assertSame(['EUR', 'USD'], array_map(static fn ($row) => $row->id, $report->currencies));
        $this->assertSame(['950.0000', '525.0000'], array_map(static fn ($row) => $row->values[$october], $report->currencies), 'the net worth by currency: what is held in euros, and 525 USD in euros');
    }

    public function testTotalsByCategoryAndAccountAreInTheUsersCurrencyToo(): void
    {
        $repository = self::getContainer()->get(TransactionRepository::class);
        [$start, $end] = [new \DateTimeImmutable('2026-10-01 00:00:00'), new \DateTimeImmutable('2026-10-03 23:59:59')];

        $byAccount = $repository->sumBySourceAccount($this->user, 'withdrawal', $start, $end);
        $this->assertEqualsWithDelta(130.0, array_sum(array_map(static fn (array $row) => (float) $row['total'], $byAccount)), 0.0001);

        $kinds = $repository->sumAllKindsByKind($this->user, $start, $end);
        $this->assertSame('130.0000', $kinds['withdrawal']);
        $this->assertSame('125.0000', $kinds['deposit']);
    }

    /** The database function and RateTable are one rule written twice: they give the same figure. */
    public function testTheDatabaseConvertsLikeTheRateTable(): void
    {
        $this->entityManager->persist(new ExchangeRateOverride($this->user, 'USD', new \DateTimeImmutable('2026-10-02'), '2.00000000'));
        $this->entityManager->flush();
        $connection = self::getContainer()->get(Connection::class);
        $table = (new CurrencyConverter(self::getContainer()->get(ExchangeRateRepository::class)))
            ->tableFor($this->user, ['USD', 'EUR'], new \DateTimeImmutable('2026-09-20'), new \DateTimeImmutable('2026-10-10'));

        foreach (['2026-09-25', '2026-10-01', '2026-10-02', '2026-10-03', '2026-10-09'] as $day) {
            foreach ([['USD', 'EUR'], ['EUR', 'USD'], ['USD', 'USD']] as [$from, $to]) {
                $sql = $connection->fetchOne('SELECT app_convert(100, ?, ?, ?::date, ?::uuid)', [$from, $to, $day, $this->user->getId()->toRfc4122()]);
                $this->assertEqualsWithDelta((float) $table->convert('100', $from, $to, $day), (float) $sql, 0.0000001, "$from → $to on $day");
            }
        }
    }

    private function asset(string $name, string $code, string $opening): Account
    {
        $account = $this->account($name, AccountType::Asset);
        $account->setAssetKind(AssetAccountKind::Checking);
        $account->setCurrency($this->entityManager->getRepository(Currency::class)->findOneBy(['code' => $code]));
        $account->setOpeningBalance($opening);
        $this->entityManager->flush();

        return $account;
    }

    private function account(string $name, AccountType $type): Account
    {
        $account = new Account($this->user, $type, $name);
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    private function money(Account $source, Account $destination, string $amount, string $currency, string $occurredAt): void
    {
        $this->entityManager->persist(new Transaction($this->user, $source, $destination, new \DateTimeImmutable($occurredAt, new \DateTimeZone('UTC')), $amount, $currency));
    }
}
