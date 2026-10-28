<?php

namespace App\Account\Service;

use App\Account\DTO\ReconcileAccountRequest;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Transaction;
use App\Repository\AccountRepository;
use App\Transaction\DTO\CreateTransactionRequest;
use App\Transaction\Service\TransactionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checking an asset account against the bank: the user gives the balance the
 * bank shows at the end of a day. When it matches FireFunds' balance that day,
 * the account remembers it (`reconciledOn`). When it doesn't, the user first
 * adds what was forgotten, or has the rest recorded as an expense or an
 * income of that day (e.g. cash taken out and never written down), so the
 * money never just vanishes: a reconciled balance always matches.
 */
class AccountReconciliation
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly TransactionService $transactionService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{difference: string, transaction: ?Transaction}
     */
    public function reconcile(Account $account, ReconcileAccountRequest $request): array
    {
        if ($account->getType() !== AccountType::Asset) {
            throw new ApiException(ErrorCode::ReconcileAssetOnly);
        }

        $day = new \DateTimeImmutable($request->day);
        $balance = bcadd($request->balance, '0', 4);
        $difference = bcsub($balance, $this->balanceOn($account, $day), 4);

        $transaction = null;
        if (bccomp($difference, '0', 4) !== 0) {
            if (!$request->settle) {
                throw new ApiException(ErrorCode::ReconciliationDifference, ['difference' => $difference], Response::HTTP_CONFLICT);
            }
            $transaction = $this->settle($account, $day, $difference, $request);
        }

        $account->markReconciled($day, $balance);
        $this->entityManager->flush();

        return ['difference' => $difference, 'transaction' => $transaction];
    }

    /** What FireFunds says the account held at the end of that day (free cash for an investment account). */
    private function balanceOn(Account $account, \DateTimeImmutable $day): string
    {
        return $this->accountRepository->getBalancesOn([$account], $day)[(string) $account->getId()] ?? '0.0000';
    }

    /** Less in the bank than here: an expense from the account; more: an income to it. Late that day. */
    private function settle(Account $account, \DateTimeImmutable $day, string $difference, ReconcileAccountRequest $request): Transaction
    {
        $out = bccomp($difference, '0', 4) < 0;
        $accountId = (string) $account->getId();

        return $this->transactionService->create($account->getUser(), new CreateTransactionRequest(
            description: $request->description,
            sourceAccountId: $out ? $accountId : null,
            destinationAccountId: $out ? null : $accountId,
            occurredAt: $day->format('Y-m-d').'T23:59:00',
            amount: ltrim($difference, '-'),
            categoryId: $request->categoryId,
        ));
    }
}
