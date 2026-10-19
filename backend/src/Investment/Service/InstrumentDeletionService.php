<?php

namespace App\Investment\Service;

use App\Core\Exception\ErrorCode;
use App\Core\Exception\InUseException;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentTransaction;
use App\Investment\DTO\InstrumentDeletionPreviewResponse;
use App\Investment\Position\AverageCostPosition;
use App\Repository\InvestmentTransactionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deleting an instrument never removes data: the instrument is marked as
 * deleted and, if the user confirms it, so are its investment transactions
 * (each with an entry in its audit log). Its price history stays untouched.
 */
class InstrumentDeletionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvestmentTransactionRepository $investmentTransactionRepository,
        private readonly InvestmentTransactionAuditLogService $auditLog,
    ) {
    }

    public function preview(InvestmentInstrument $instrument): InstrumentDeletionPreviewResponse
    {
        $transactions = $this->transactionsOf($instrument);

        return new InstrumentDeletionPreviewResponse(
            investmentTransactions: \count($transactions),
            openPositions: $this->countOpenPositions($transactions),
        );
    }

    /**
     * @throws InUseException when the instrument has investment transactions and $deleteTransactions is false
     */
    public function delete(InvestmentInstrument $instrument, bool $deleteTransactions): void
    {
        $transactions = $this->transactionsOf($instrument);

        if ($transactions !== [] && !$deleteTransactions) {
            throw new InUseException(ErrorCode::InstrumentInUse, ['transactions' => \count($transactions)]);
        }

        $this->entityManager->wrapInTransaction(function () use ($instrument, $transactions): void {
            foreach ($transactions as $transaction) {
                $transaction->markDeleted();
                $this->auditLog->logDeleted($transaction, $transaction->getUser());
            }

            $instrument->markDeleted();
        });
    }

    /**
     * @return InvestmentTransaction[] oldest first
     */
    private function transactionsOf(InvestmentInstrument $instrument): array
    {
        return $this->investmentTransactionRepository->findBy(['instrument' => $instrument], ['occurredAt' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * How many accounts still hold this instrument.
     *
     * @param InvestmentTransaction[] $transactions oldest first
     */
    private function countOpenPositions(array $transactions): int
    {
        $byAccount = [];
        foreach ($transactions as $transaction) {
            $position = $byAccount[$transaction->getAccount()->getId()->toRfc4122()] ??= new AverageCostPosition();
            $position->apply($transaction->getType()->value, $transaction->getQuantity(), $transaction->getAmount(), $transaction->getCosts());
        }

        return \count(array_filter($byAccount, static fn (AverageCostPosition $position) => !$position->isClosed()));
    }
}
