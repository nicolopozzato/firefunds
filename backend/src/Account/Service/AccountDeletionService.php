<?php

namespace App\Account\Service;

use App\Account\DTO\AccountDeletionPreviewResponse;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Core\Exception\InUseException;
use App\Entity\Account;
use App\Entity\InvestmentTransaction;
use App\Entity\Transaction;
use App\Investment\Service\InvestmentTransactionAuditLogService;
use App\Repository\AccountRepository;
use App\Repository\InvestmentTransactionRepository;
use App\Repository\TransactionRepository;
use App\Transaction\Service\TransactionAuditLogService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deleting an account never removes data: the account is marked as deleted,
 * and its transactions are either moved to another account of the same kind
 * or marked as deleted with it. When moved, a non-zero opening balance moves
 * too, as a transaction from/to "external" named after the deleted account.
 * Every transaction touched or created gets an entry in its own audit log.
 */
class AccountDeletionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountRepository $accountRepository,
        private readonly TransactionRepository $transactionRepository,
        private readonly InvestmentTransactionRepository $investmentTransactionRepository,
        private readonly TransactionAuditLogService $transactionAuditLog,
        private readonly InvestmentTransactionAuditLogService $investmentTransactionAuditLog,
    ) {
    }

    public function preview(Account $account): AccountDeletionPreviewResponse
    {
        return new AccountDeletionPreviewResponse(
            openingBalance: $this->hasOpeningBalance($account) ? $account->getOpeningBalance() : null,
            transactions: $this->transactionRepository->countByAccount($account),
            investmentTransactions: $this->investmentTransactionRepository->count(['account' => $account]),
            openPositions: \count($this->investmentTransactionRepository->getPositions($account)),
            transferTargets: array_map(
                static fn (Account $target) => ['id' => $target->getId()->toRfc4122(), 'name' => $target->getName()],
                $this->transferTargets($account)
            ),
        );
    }

    /**
     * @throws InUseException           when the account has transactions and neither option was chosen
     * @throws ApiException             when $transferToId isn't a valid target for this account
     */
    public function delete(Account $account, ?string $transferToId, bool $deleteTransactions): void
    {
        $transactions = $this->transactionRepository->findByAccount($account);
        $investmentTransactions = $this->investmentTransactionRepository->findBy(['account' => $account]);
        $inUse = $transactions !== [] || $investmentTransactions !== [] || $this->hasOpeningBalance($account);

        // Validated before any write, so a refused request changes nothing.
        $target = $inUse && $transferToId !== null ? $this->resolveTransferTarget($account, $transferToId) : null;

        if ($inUse && $target === null && !$deleteTransactions) {
            throw new InUseException(ErrorCode::AccountInUse, [
                'transactions' => \count($transactions),
                'investmentTransactions' => \count($investmentTransactions),
            ]);
        }

        $this->entityManager->wrapInTransaction(function () use ($account, $target, $transactions, $investmentTransactions): void {
            if ($target !== null) {
                $this->carryOverOpeningBalance($account, $target, $transactions, $investmentTransactions);
                $this->moveTransactions($account, $target, $transactions, $investmentTransactions);
            } else {
                $this->deleteTransactions($transactions, $investmentTransactions);
            }

            $account->markDeleted();
        });
    }

    /**
     * Accounts that can take over this one's transactions: same owner, same
     * type, same asset kind (checking -> checking, investment -> investment)
     * and same currency, so every amount keeps its meaning.
     *
     * @return Account[]
     */
    private function transferTargets(Account $account): array
    {
        return array_values(array_filter(
            $this->accountRepository->findByUserAndType($account->getUser(), $account->getType()),
            static fn (Account $candidate) => !$candidate->getId()->equals($account->getId())
                && $candidate->getAssetKind() === $account->getAssetKind()
                && $candidate->getCurrency()?->getCode() === $account->getCurrency()?->getCode(),
        ));
    }

    private function resolveTransferTarget(Account $account, string $transferToId): Account
    {
        foreach ($this->transferTargets($account) as $candidate) {
            if ($candidate->getId()->toRfc4122() === strtolower($transferToId)) {
                return $candidate;
            }
        }

        throw new ApiException(ErrorCode::TransferTargetInvalid);
    }

    /**
     * A transfer between the deleted account and the target itself would end
     * up going from the target to the target, so it is deleted instead.
     *
     * @param \App\Entity\Transaction[] $transactions
     * @param \App\Entity\InvestmentTransaction[] $investmentTransactions
     */
    private function moveTransactions(Account $from, Account $to, array $transactions, array $investmentTransactions): void
    {
        // Null is the "external" side and stays as it is.
        $isFrom = static fn (?Account $side) => $side !== null && $side->getId()->equals($from->getId());

        foreach ($transactions as $transaction) {
            $source = $isFrom($transaction->getSourceAccount()) ? $to : $transaction->getSourceAccount();
            $destination = $isFrom($transaction->getDestinationAccount()) ? $to : $transaction->getDestinationAccount();

            if ($source !== null && $destination !== null && $source->getId()->equals($destination->getId())) {
                $transaction->markDeleted();
                $this->transactionAuditLog->logDeleted($transaction, $transaction->getUser());
                continue;
            }

            $before = $this->transactionAuditLog->snapshot($transaction);
            $transaction->setSourceAccount($source);
            $transaction->setDestinationAccount($destination);
            $this->transactionAuditLog->logUpdated($transaction, $transaction->getUser(), $before);
        }

        foreach ($investmentTransactions as $transaction) {
            $before = $this->investmentTransactionAuditLog->snapshot($transaction);
            $transaction->setAccount($to);
            $this->investmentTransactionAuditLog->logUpdated($transaction, $transaction->getUser(), $before);
        }
    }

    private function hasOpeningBalance(Account $account): bool
    {
        return $account->getOpeningBalance() !== null && bccomp($account->getOpeningBalance(), '0', 4) !== 0;
    }

    /**
     * Keeps the deleted account's opening balance in the target's history: a
     * deposit from "external" (a withdrawal to it, if negative) named after the
     * deleted account, dated on its opening balance date, or else a minute
     * before its first operation.
     *
     * @param Transaction[] $transactions
     * @param InvestmentTransaction[] $investmentTransactions
     */
    private function carryOverOpeningBalance(Account $from, Account $to, array $transactions, array $investmentTransactions): void
    {
        if (!$this->hasOpeningBalance($from)) {
            return;
        }

        $opening = $from->getOpeningBalance();
        $isPositive = bccomp($opening, '0', 4) > 0;

        $transaction = new Transaction(
            user: $from->getUser(),
            sourceAccount: $isPositive ? null : $to,
            destinationAccount: $isPositive ? $to : null,
            occurredAt: $this->openingBalanceMoment($from, $transactions, $investmentTransactions),
            amount: $isPositive ? $opening : bcmul($opening, '-1', 4),
            currency: $to->getCurrency()?->getCode() ?? $from->getCurrency()?->getCode() ?? '',
        );
        $transaction->setDescription($from->getName());

        $this->entityManager->persist($transaction);
        $this->transactionAuditLog->logCreated($transaction, $from->getUser());
    }

    /**
     * @param Transaction[] $transactions
     * @param InvestmentTransaction[] $investmentTransactions
     */
    private function openingBalanceMoment(Account $account, array $transactions, array $investmentTransactions): \DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');

        if ($account->getOpeningBalanceDate() !== null) {
            // Midnight of that day in the owner's timezone, stored as UTC like every occurredAt.
            return (new \DateTimeImmutable($account->getOpeningBalanceDate()->format('Y-m-d'), new \DateTimeZone($account->getUser()->getTimezone())))
                ->setTimezone($utc);
        }

        $dates = array_map(
            static fn (Transaction|InvestmentTransaction $transaction) => $transaction->getOccurredAt(),
            [...$transactions, ...$investmentTransactions]
        );

        return $dates !== [] ? min($dates)->modify('-1 minute') : $account->getCreatedAt()->setTimezone($utc);
    }

    /**
     * @param \App\Entity\Transaction[] $transactions
     * @param \App\Entity\InvestmentTransaction[] $investmentTransactions
     */
    private function deleteTransactions(array $transactions, array $investmentTransactions): void
    {
        foreach ($transactions as $transaction) {
            $transaction->markDeleted();
            $this->transactionAuditLog->logDeleted($transaction, $transaction->getUser());
        }

        foreach ($investmentTransactions as $transaction) {
            $transaction->markDeleted();
            $this->investmentTransactionAuditLog->logDeleted($transaction, $transaction->getUser());
        }
    }
}
