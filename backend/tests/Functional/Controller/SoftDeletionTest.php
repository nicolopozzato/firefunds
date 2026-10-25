<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Account;
use App\Entity\AccountAdjustment;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\Category;
use App\Entity\Currency;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentInstrumentType;
use App\Entity\InvestmentPrice;
use App\Entity\InvestmentTransaction;
use App\Entity\InvestmentTransactionType;
use App\Entity\Tag;
use App\Entity\Transaction;
use App\Entity\User;
use App\Tests\Functional\WebTestCaseBase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Requirement: nothing is ever physically deleted. Every DELETE marks the row
 * as deleted and deals explicitly with the data that depends on it.
 */
class SoftDeletionTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private User $user;
    private Currency $eur;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->user = static::createUser();
        static::authenticate($this->client, $this->user);

        $eur = $this->entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']);
        if ($eur === null) {
            $eur = new Currency('EUR', 'Euro', '€', 2);
            $this->entityManager->persist($eur);
            $this->entityManager->flush();
        }
        $this->eur = $eur;
    }

    public function testAccountWithoutTransactionsIsMarkedDeleted(): void
    {
        $account = $this->assetAccount('Old savings', AssetAccountKind::Checking);

        $this->request('DELETE', '/api/accounts/'.$this->id($account));
        $this->assertResponseStatusCodeSame(204);

        $this->assertMarkedDeleted('account', $account);
        $this->request('GET', '/api/accounts/'.$this->id($account));
        $this->assertResponseStatusCodeSame(404);
    }

    public function testAccountWithTransactionsNeedsAnExplicitChoice(): void
    {
        $checking = $this->assetAccount('Checking', AssetAccountKind::Checking);
        $groceries = $this->expenseAccount('Groceries');
        $transaction = $this->transaction($checking, $groceries, '30.0000');

        $this->request('DELETE', '/api/accounts/'.$this->id($checking));
        $this->assertResponseStatusCodeSame(409);

        $this->assertNotMarkedDeleted('account', $checking);
        $this->assertNotMarkedDeleted('transaction', $transaction);
    }

    public function testPreviewCountsTransactionsAndListsCompatibleTargetsOnly(): void
    {
        $checking = $this->assetAccount('Checking', AssetAccountKind::Checking);
        $otherChecking = $this->assetAccount('Other checking', AssetAccountKind::Checking);
        $this->assetAccount('Broker', AssetAccountKind::Investment);
        $usd = $this->assetAccount('USD checking', AssetAccountKind::Checking, $this->currency('USD'));
        $this->transaction($checking, $this->expenseAccount('Groceries'), '30.0000');
        $this->transaction($checking, $otherChecking, '100.0000');

        $preview = $this->request('GET', '/api/accounts/'.$this->id($checking).'/deletion-preview');

        $this->assertSame(2, $preview['transactions']);
        $this->assertSame(0, $preview['investmentTransactions']);
        $this->assertSame([['id' => $this->id($otherChecking), 'name' => 'Other checking']], $preview['transferTargets']);
        $this->assertNotContains($this->id($usd), array_column($preview['transferTargets'], 'id'));
    }

    /**
     * Moving to another checking account: expenses follow it, and a transfer
     * between the two accounts (which would become target -> target) is
     * deleted instead. The target's balance takes over the moved expenses.
     */
    public function testTransferMovesTransactionsAndDeletesTheAccount(): void
    {
        $old = $this->assetAccount('Old checking', AssetAccountKind::Checking);
        $new = $this->assetAccount('New checking', AssetAccountKind::Checking, opening: '1000.0000');
        $groceries = $this->expenseAccount('Groceries');
        $expense = $this->transaction($old, $groceries, '30.0000');
        $transferBetween = $this->transaction($new, $old, '100.0000');

        $this->request('DELETE', '/api/accounts/'.$this->id($old).'?transferTo='.$this->id($new));
        $this->assertResponseStatusCodeSame(204);

        $this->assertMarkedDeleted('account', $old);
        $this->assertSame($this->id($new), $this->connection->fetchOne('SELECT source_account_id FROM transaction WHERE id = ?', [$this->id($expense)]));
        $this->assertSame(['updated'], $this->auditActions('transaction_audit_log', 'transaction_id', $expense));
        $this->assertMarkedDeleted('transaction', $transferBetween);
        $this->assertSame(['deleted'], $this->auditActions('transaction_audit_log', 'transaction_id', $transferBetween));

        $this->assertSame('970.0000', $this->request('GET', '/api/accounts/'.$this->id($new))['currentBalance']);
    }

    /**
     * The old account's 100 € opening balance isn't lost: it lands on the
     * target as one of its other changes (an adjustment), named after the old
     * account and dated on its opening balance date. Not a transaction, so not
     * income either.
     */
    public function testTransferCarriesTheOpeningBalanceOverAsAnAdjustment(): void
    {
        $old = $this->assetAccount('Vecchio', AssetAccountKind::Checking, opening: '100.0000');
        $old->setOpeningBalanceDate(new \DateTimeImmutable('2026-08-15'));
        $new = $this->assetAccount('Nuovo', AssetAccountKind::Checking);
        $this->transaction($old, $this->expenseAccount('Groceries'), '20.0000');

        $this->assertSame('100.0000', $this->request('GET', '/api/accounts/'.$this->id($old).'/deletion-preview')['openingBalance']);

        $this->request('DELETE', '/api/accounts/'.$this->id($old).'?transferTo='.$this->id($new));
        $this->assertResponseStatusCodeSame(204);

        $account = $this->request('GET', '/api/accounts/'.$this->id($new));
        $this->assertSame('80.0000', $account['currentBalance']);
        $this->assertSame(
            [['date' => '2026-08-15', 'amount' => '100.0000', 'description' => 'Vecchio']],
            array_map(static fn (array $adjustment) => array_diff_key($adjustment, ['id' => true]), $account['adjustments'])
        );
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM transaction WHERE description = ? AND user_id = ?', ['Vecchio', $this->id($this->user)]));
    }

    public function testWithoutOpeningDateTheBalanceIsDatedOnTheFirstOperation(): void
    {
        $old = $this->assetAccount('Vecchio', AssetAccountKind::Checking, opening: '100.0000');
        $new = $this->assetAccount('Nuovo', AssetAccountKind::Checking);
        $this->transaction($old, $this->expenseAccount('Groceries'), '20.0000'); // 2026-09-01 10:00

        $this->request('DELETE', '/api/accounts/'.$this->id($old).'?transferTo='.$this->id($new));
        $this->assertResponseStatusCodeSame(204);

        $this->assertSame('2026-09-01', $this->request('GET', '/api/accounts/'.$this->id($new))['adjustments'][0]['date']);
    }

    public function testNegativeOpeningBalanceBecomesANegativeAdjustment(): void
    {
        $old = $this->assetAccount('Scoperto', AssetAccountKind::Checking, opening: '-40.0000');
        $new = $this->assetAccount('Nuovo', AssetAccountKind::Checking, opening: '100.0000');

        $this->request('DELETE', '/api/accounts/'.$this->id($old).'?transferTo='.$this->id($new));
        $this->assertResponseStatusCodeSame(204);

        $account = $this->request('GET', '/api/accounts/'.$this->id($new));
        $this->assertSame('60.0000', $account['currentBalance']);
        $this->assertSame('-40.0000', $account['adjustments'][0]['amount']);
    }

    /** The old account's own adjustments move with its transactions, or are deleted with them. */
    public function testAdjustmentsFollowTheTransactions(): void
    {
        $old = $this->assetAccount('Vecchio', AssetAccountKind::Checking);
        $new = $this->assetAccount('Nuovo', AssetAccountKind::Checking);
        $moved = $this->adjustment($old, '2026-09-10', '25.0000');
        $own = $this->adjustment($new, '2026-09-11', '5.0000');

        $this->assertSame(1, $this->request('GET', '/api/accounts/'.$this->id($old).'/deletion-preview')['adjustments']);
        $this->request('DELETE', '/api/accounts/'.$this->id($old));
        $this->assertResponseStatusCodeSame(409);

        $this->request('DELETE', '/api/accounts/'.$this->id($old).'?transferTo='.$this->id($new));
        $this->assertResponseStatusCodeSame(204);
        $this->assertSame($this->id($new), $this->connection->fetchOne('SELECT account_id FROM account_adjustment WHERE id = ?', [$this->id($moved)]));
        $this->assertSame('30.0000', $this->request('GET', '/api/accounts/'.$this->id($new))['currentBalance']);

        $this->request('DELETE', '/api/accounts/'.$this->id($new).'?deleteTransactions=1');
        $this->assertResponseStatusCodeSame(204);
        $this->assertMarkedDeleted('account_adjustment', $own);
        $this->assertMarkedDeleted('account_adjustment', $moved);
    }

    /** Only an opening balance, no transactions: still needs a choice, so the money isn't dropped silently. */
    public function testAccountWithOnlyAnOpeningBalanceNeedsAChoice(): void
    {
        $old = $this->assetAccount('Vecchio', AssetAccountKind::Checking, opening: '100.0000');

        $this->request('DELETE', '/api/accounts/'.$this->id($old));
        $this->assertResponseStatusCodeSame(409);

        $this->request('DELETE', '/api/accounts/'.$this->id($old).'?deleteTransactions=1');
        $this->assertResponseStatusCodeSame(204);
        $this->assertMarkedDeleted('account', $old);
    }

    public function testTransferToAnIncompatibleAccountIsRefusedAndChangesNothing(): void
    {
        $checking = $this->assetAccount('Checking', AssetAccountKind::Checking);
        $broker = $this->assetAccount('Broker', AssetAccountKind::Investment);
        $transaction = $this->transaction($checking, $this->expenseAccount('Groceries'), '30.0000');

        $this->request('DELETE', '/api/accounts/'.$this->id($checking).'?transferTo='.$this->id($broker));
        $this->assertResponseStatusCodeSame(400);

        $this->assertNotMarkedDeleted('account', $checking);
        $this->assertSame($this->id($checking), $this->connection->fetchOne('SELECT source_account_id FROM transaction WHERE id = ?', [$this->id($transaction)]));
    }

    public function testDeleteWithTransactionsMarksEverythingDeleted(): void
    {
        $broker = $this->assetAccount('Broker', AssetAccountKind::Investment);
        $checking = $this->assetAccount('Checking', AssetAccountKind::Checking);
        $funding = $this->transaction($checking, $broker, '500.0000');
        $trade = $this->trade($broker, $this->instrument('ETF'), '1', '100.0000');

        $preview = $this->request('GET', '/api/accounts/'.$this->id($broker).'/deletion-preview');
        $this->assertSame([1, 1, 1], [$preview['transactions'], $preview['investmentTransactions'], $preview['openPositions']]);

        $this->request('DELETE', '/api/accounts/'.$this->id($broker).'?deleteTransactions=1');
        $this->assertResponseStatusCodeSame(204);

        $this->assertMarkedDeleted('account', $broker);
        $this->assertMarkedDeleted('transaction', $funding);
        $this->assertMarkedDeleted('investment_transaction', $trade);
        $this->assertSame(['deleted'], $this->auditActions('investment_transaction_audit_log', 'investment_transaction_id', $trade));
    }

    public function testBrokerTransferMovesItsPositions(): void
    {
        $old = $this->assetAccount('Old broker', AssetAccountKind::Investment);
        $new = $this->assetAccount('New broker', AssetAccountKind::Investment);
        $instrument = $this->instrument('ETF');
        $trade = $this->trade($old, $instrument, '2', '100.0000');

        $this->request('DELETE', '/api/accounts/'.$this->id($old).'?transferTo='.$this->id($new));
        $this->assertResponseStatusCodeSame(204);

        $positions = $this->request('GET', '/api/accounts/'.$this->id($new).'/positions?from=2026-09-01&to=2026-09-01');
        $this->assertSame([$this->id($instrument)], array_column($positions, 'instrumentId'));
        $this->assertSame(['updated'], $this->auditActions('investment_transaction_audit_log', 'investment_transaction_id', $trade));
    }

    public function testInstrumentWithTransactionsNeedsConfirmationAndKeepsPrices(): void
    {
        $broker = $this->assetAccount('Broker', AssetAccountKind::Investment);
        $instrument = $this->instrument('ETF');
        $trade = $this->trade($broker, $instrument, '1', '100.0000');
        $this->entityManager->persist(new InvestmentPrice($instrument, new \DateTimeImmutable('2026-09-01'), '100.0000'));
        $this->entityManager->flush();

        $preview = $this->request('GET', '/api/investment-instruments/'.$this->id($instrument).'/deletion-preview');
        $this->assertSame(['investmentTransactions' => 1, 'openPositions' => 1], $preview);

        $this->request('DELETE', '/api/investment-instruments/'.$this->id($instrument));
        $this->assertResponseStatusCodeSame(409);
        $this->assertNotMarkedDeleted('investment_instrument', $instrument);

        $this->request('DELETE', '/api/investment-instruments/'.$this->id($instrument).'?deleteTransactions=1');
        $this->assertResponseStatusCodeSame(204);

        $this->assertMarkedDeleted('investment_instrument', $instrument);
        $this->assertMarkedDeleted('investment_transaction', $trade);
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM investment_price WHERE instrument_id = ?', [$this->id($instrument)]));
    }

    public function testDeletedCategoryIsTakenOffTransactionsAndKept(): void
    {
        $category = new Category($this->user, 'Food');
        $this->entityManager->persist($category);
        $transaction = $this->transaction($this->assetAccount('Checking', AssetAccountKind::Checking), $this->expenseAccount('Groceries'), '30.0000');
        $transaction->setCategory($category);
        $this->entityManager->flush();

        $this->assertSame(['transactions' => 1], $this->request('GET', '/api/categories/'.$this->id($category).'/deletion-preview'));

        $this->request('DELETE', '/api/categories/'.$this->id($category));
        $this->assertResponseStatusCodeSame(204);

        $this->assertMarkedDeleted('category', $category);
        $this->assertNull($this->connection->fetchOne('SELECT category_id FROM transaction WHERE id = ?', [$this->id($transaction)]));
        $this->assertSame(['updated'], $this->auditActions('transaction_audit_log', 'transaction_id', $transaction));
        $this->assertSame(0, $this->request('GET', '/api/categories')['total']);
    }

    public function testDeletedTagIsTakenOffTransactionsAndKept(): void
    {
        $tag = new Tag($this->user, 'Holiday');
        $this->entityManager->persist($tag);
        $transaction = $this->transaction($this->assetAccount('Checking', AssetAccountKind::Checking), $this->expenseAccount('Hotels'), '80.0000');
        $transaction->addTag($tag);
        $this->entityManager->flush();

        $this->assertSame(['transactions' => 1], $this->request('GET', '/api/tags/'.$this->id($tag).'/deletion-preview'));

        $this->request('DELETE', '/api/tags/'.$this->id($tag));
        $this->assertResponseStatusCodeSame(204);

        $this->assertMarkedDeleted('tag', $tag);
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM transaction_tag WHERE tag_id = ?', [$this->id($tag)]));
        $this->assertSame(['updated'], $this->auditActions('transaction_audit_log', 'transaction_id', $transaction));
    }

    private function assetAccount(string $name, AssetAccountKind $kind, ?Currency $currency = null, string $opening = '0.0000'): Account
    {
        $account = new Account($this->user, AccountType::Asset, $name);
        $account->setAssetKind($kind);
        $account->setCurrency($currency ?? $this->eur);
        $account->setOpeningBalance($opening);
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    private function adjustment(Account $account, string $date, string $amount): AccountAdjustment
    {
        $adjustment = new AccountAdjustment($account, new \DateTimeImmutable($date), $amount);
        $this->entityManager->persist($adjustment);
        $this->entityManager->flush();

        return $adjustment;
    }

    private function expenseAccount(string $name): Account
    {
        $account = new Account($this->user, AccountType::Expense, $name);
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    private function currency(string $code): Currency
    {
        $currency = $this->entityManager->getRepository(Currency::class)->findOneBy(['code' => $code]);
        if ($currency === null) {
            $currency = new Currency($code, $code, $code, 2);
            $this->entityManager->persist($currency);
            $this->entityManager->flush();
        }

        return $currency;
    }

    private function transaction(Account $source, Account $destination, string $amount): Transaction
    {
        $transaction = new Transaction(
            user: $this->user,
            sourceAccount: $source,
            destinationAccount: $destination,
            occurredAt: new \DateTimeImmutable('2026-09-01 10:00'),
            amount: $amount,
            currency: 'EUR',
        );
        $transaction->setDescription('Test');
        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        return $transaction;
    }

    private function instrument(string $name): InvestmentInstrument
    {
        $instrument = new InvestmentInstrument($this->user, $name, InvestmentInstrumentType::Etf);
        $this->entityManager->persist($instrument);
        $this->entityManager->flush();

        return $instrument;
    }

    private function trade(Account $broker, InvestmentInstrument $instrument, string $quantity, string $unitPrice): InvestmentTransaction
    {
        $trade = new InvestmentTransaction($this->user, $broker, $instrument, InvestmentTransactionType::Buy, $quantity, $unitPrice, new \DateTimeImmutable('2026-09-01 10:00'));
        $this->entityManager->persist($trade);
        $this->entityManager->flush();

        return $trade;
    }

    /**
     * @return mixed decoded JSON body (null for an empty response)
     */
    private function request(string $method, string $uri, ?array $json = null): mixed
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: $json !== null ? json_encode($json) : null);
        $content = $this->client->getResponse()->getContent();

        return $content !== '' ? json_decode($content, true) : null;
    }

    private function id(object $entity): string
    {
        return $entity->getId()->toRfc4122();
    }

    private function assertMarkedDeleted(string $table, object $entity): void
    {
        $deletedAt = $this->connection->fetchOne(\sprintf('SELECT deleted_at FROM %s WHERE id = ?', $table), [$this->id($entity)]);
        $this->assertNotFalse($deletedAt, \sprintf('%s row was physically removed', $table));
        $this->assertNotNull($deletedAt, \sprintf('%s row is not marked as deleted', $table));
    }

    private function assertNotMarkedDeleted(string $table, object $entity): void
    {
        $this->assertNull($this->connection->fetchOne(\sprintf('SELECT deleted_at FROM %s WHERE id = ?', $table), [$this->id($entity)]));
    }

    /**
     * @return string[]
     */
    private function auditActions(string $table, string $column, object $entity): array
    {
        return $this->connection->fetchFirstColumn(\sprintf('SELECT action FROM %s WHERE %s = ? ORDER BY created_at', $table, $column), [$this->id($entity)]);
    }
}
