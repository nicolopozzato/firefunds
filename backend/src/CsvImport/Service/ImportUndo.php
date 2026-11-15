<?php

namespace App\CsvImport\Service;

use App\Account\Service\AccountAuditLogService;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Entity\Account;
use App\Entity\AccountAdjustment;
use App\Entity\Category;
use App\Entity\ImportBatch;
use App\Entity\InvestmentTransaction;
use App\Entity\RecurringTransaction;
use App\Entity\Tag;
use App\Entity\Transaction;
use App\Transaction\Service\TransactionAuditLogService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Undoing a whole import: the transactions and balance adjustments it
 * recorded that are still there are deleted (soft, each transaction's
 * history says so), then the accounts, categories and tags it created, but
 * only those nothing uses any more (the user may have recorded something on
 * them since). Recurring transactions an imported row counted as dealt with
 * stay as they are.
 */
class ImportUndo
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionAuditLogService $transactionAuditLog,
        private readonly AccountAuditLogService $accountAuditLog,
    ) {
    }

    /** @return array{transactions: int, adjustments: int, accounts: int, categories: int, tags: int} how many of each were deleted */
    public function undo(ImportBatch $batch): array
    {
        if ($batch->getUndoneAt() !== null) {
            throw new ApiException(ErrorCode::ImportAlreadyUndone);
        }

        return $this->entityManager->wrapInTransaction(function () use ($batch): array {
            $user = $batch->getUser();
            $count = ['transactions' => 0, 'adjustments' => 0, 'accounts' => 0, 'categories' => 0, 'tags' => 0];

            foreach ($this->entityManager->getRepository(Transaction::class)->findBy(['importBatch' => $batch]) as $transaction) {
                $transaction->markDeleted();
                $this->transactionAuditLog->logDeleted($transaction, $user);
                ++$count['transactions'];
            }
            foreach ($this->entityManager->getRepository(AccountAdjustment::class)->findBy(['importBatch' => $batch]) as $adjustment) {
                $adjustment->markDeleted();
                ++$count['adjustments'];
            }
            $this->entityManager->flush();

            foreach ($this->created(Account::class, $batch->getCreatedAccountIds(), $batch) as $account) {
                if (!$this->accountInUse($account)) {
                    $account->markDeleted();
                    $this->accountAuditLog->logDeleted($account, $user);
                    ++$count['accounts'];
                }
            }
            foreach ($this->created(Category::class, $batch->getCreatedCategoryIds(), $batch) as $category) {
                if (!$this->used('SELECT COUNT(t.id) FROM '.Transaction::class.' t WHERE t.category = :it', $category)
                    && !$this->used('SELECT COUNT(r.id) FROM '.RecurringTransaction::class.' r WHERE r.category = :it', $category)) {
                    $category->markDeleted();
                    ++$count['categories'];
                }
            }
            foreach ($this->created(Tag::class, $batch->getCreatedTagIds(), $batch) as $tag) {
                if (!$this->used('SELECT COUNT(t.id) FROM '.Transaction::class.' t WHERE :it MEMBER OF t.tags', $tag)
                    && !$this->used('SELECT COUNT(r.id) FROM '.RecurringTransaction::class.' r WHERE :it MEMBER OF r.tags', $tag)) {
                    $tag->markDeleted();
                    ++$count['tags'];
                }
            }

            $batch->markUndone();
            $this->entityManager->flush();

            return $count;
        });
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param list<string> $ids
     * @return list<T> those still there (not deleted since)
     */
    private function created(string $class, array $ids, ImportBatch $batch): array
    {
        return $ids === [] ? [] : $this->entityManager->getRepository($class)->findBy(['id' => $ids, 'user' => $batch->getUser()]);
    }

    private function accountInUse(Account $account): bool
    {
        return $this->used('SELECT COUNT(t.id) FROM '.Transaction::class.' t WHERE t.sourceAccount = :it OR t.destinationAccount = :it', $account)
            || $this->used('SELECT COUNT(a.id) FROM '.AccountAdjustment::class.' a WHERE a.account = :it', $account)
            || $this->used('SELECT COUNT(i.id) FROM '.InvestmentTransaction::class.' i WHERE i.account = :it', $account)
            || $this->used('SELECT COUNT(r.id) FROM '.RecurringTransaction::class.' r WHERE r.sourceAccount = :it OR r.destinationAccount = :it', $account);
    }

    /** Whether the query (soft-deleted rows left out, as in every ORM query) counts anything. */
    private function used(string $dql, object $it): bool
    {
        return (int) $this->entityManager->createQuery($dql)->setParameter('it', $it)->getSingleScalarResult() > 0;
    }
}
