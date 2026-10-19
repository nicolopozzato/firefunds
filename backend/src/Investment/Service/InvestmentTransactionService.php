<?php

namespace App\Investment\Service;

use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentTransaction;
use App\Entity\User;
use App\Investment\DTO\CreateInvestmentTransactionRequest;
use App\Investment\DTO\SavedInvestmentTransaction;
use App\Repository\AccountRepository;
use App\Repository\InvestmentInstrumentRepository;
use Doctrine\ORM\EntityManagerInterface;

class InvestmentTransactionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountRepository $accountRepository,
        private readonly InvestmentInstrumentRepository $instrumentRepository,
        private readonly InvestmentTransactionAuditLogService $auditLogService,
        private readonly InvestmentPriceService $priceService,
    ) {
    }

    public function create(User $user, CreateInvestmentTransactionRequest $request): SavedInvestmentTransaction
    {
        $account = $this->resolveBrokerAccount($user, $request->accountId);
        $instrument = $this->resolveOwnedInstrument($user, $request->instrumentId);
        $type = $request->getInvestmentTransactionType();

        $transaction = new InvestmentTransaction(
            user: $user,
            account: $account,
            instrument: $instrument,
            type: $type,
            quantity: $request->quantity,
            unitPrice: $request->unitPrice,
            occurredAt: $this->parseOccurredAt($request->occurredAt),
            amount: $this->resolveAmount($request),
        );
        $transaction->setFees($request->fees);
        $transaction->setTaxes($request->taxes);
        $transaction->setDescription($request->description);
        $transaction->setNotes($request->notes);

        $this->entityManager->persist($transaction);
        $this->auditLogService->logCreated($transaction, $user);
        $this->entityManager->flush();

        $pricesComplete = $this->priceService->ensureCompletePriceHistoryThroughYesterday($instrument);

        return new SavedInvestmentTransaction($transaction, pricesIncomplete: !$pricesComplete);
    }

    public function update(InvestmentTransaction $transaction, CreateInvestmentTransactionRequest $request): SavedInvestmentTransaction
    {
        $before = $this->auditLogService->snapshot($transaction);

        $account = $this->resolveBrokerAccount($transaction->getUser(), $request->accountId);
        $instrument = $this->resolveOwnedInstrument($transaction->getUser(), $request->instrumentId);
        $type = $request->getInvestmentTransactionType();

        $transaction->setAccount($account);
        $transaction->setInstrument($instrument);
        $transaction->setType($type);
        $transaction->setQuantity($request->quantity);
        $transaction->setUnitPrice($request->unitPrice);
        $transaction->setAmount($this->resolveAmount($request));
        $transaction->setFees($request->fees);
        $transaction->setTaxes($request->taxes);
        $transaction->setOccurredAt($this->parseOccurredAt($request->occurredAt));
        $transaction->setDescription($request->description);
        $transaction->setNotes($request->notes);

        $this->auditLogService->logUpdated($transaction, $transaction->getUser(), $before);
        $this->entityManager->flush();

        $pricesComplete = $this->priceService->ensureCompletePriceHistoryThroughYesterday($instrument);

        return new SavedInvestmentTransaction($transaction, pricesIncomplete: !$pricesComplete);
    }

    public function delete(InvestmentTransaction $transaction): void
    {
        $transaction->markDeleted();
        $this->auditLogService->logDeleted($transaction, $transaction->getUser());
        $this->entityManager->flush();
    }

    /**
     * The amount the user entered, or quantity x unit price when left out.
     */
    private function resolveAmount(CreateInvestmentTransactionRequest $request): string
    {
        if ($request->amount === null || $request->amount === '') {
            return InvestmentTransaction::grossAmount($request->quantity, $request->unitPrice);
        }

        if (!InvestmentTransaction::isPlausibleAmount($request->quantity, $request->unitPrice, $request->amount)) {
            throw new ApiException(ErrorCode::AmountFarFromQuantityTimesPrice, [
                'amount' => $request->amount,
                'expected' => InvestmentTransaction::grossAmount($request->quantity, $request->unitPrice),
            ]);
        }

        return bcadd($request->amount, '0', 4);
    }

    /**
     * The `occurred_at` column is a timestamp without time zone, so Doctrine writes
     * whatever wall-clock components the DateTimeImmutable carries, ignoring its
     * offset. Normalizing to UTC here keeps that literal write meaningful and
     * matches how the value is interpreted when read back (PHP's default timezone
     * is UTC).
     */
    private function parseOccurredAt(string $value): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
    }

    private function resolveBrokerAccount(User $user, string $accountId): Account
    {
        $account = $this->accountRepository->findOneByUserAndId($user, $accountId);

        if ($account === null) {
            throw new ApiException(ErrorCode::AccountNotFound);
        }

        if ($account->getType() !== AccountType::Asset || $account->getAssetKind() !== AssetAccountKind::Investment) {
            throw new ApiException(ErrorCode::InvestmentAccountRequired);
        }

        return $account;
    }

    private function resolveOwnedInstrument(User $user, string $instrumentId): InvestmentInstrument
    {
        $instrument = $this->instrumentRepository->findOneByUserAndId($user, $instrumentId);

        if ($instrument === null) {
            throw new ApiException(ErrorCode::InstrumentNotFound);
        }

        return $instrument;
    }
}
