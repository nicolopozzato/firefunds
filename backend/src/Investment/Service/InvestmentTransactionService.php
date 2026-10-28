<?php

namespace App\Investment\Service;

use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Core\Period\TransactionMoment;
use App\Currency\Service\CurrencyConverter;
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
use App\Repository\InvestmentTransactionRepository;
use App\Core\Doctrine\RestoreWindow;
use Doctrine\ORM\EntityManagerInterface;

class InvestmentTransactionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountRepository $accountRepository,
        private readonly InvestmentInstrumentRepository $instrumentRepository,
        private readonly InvestmentTransactionAuditLogService $auditLogService,
        private readonly InvestmentPriceService $priceService,
        private readonly CurrencyConverter $converter,
        private readonly InvestmentTransactionRepository $transactionRepository,
        private readonly RestoreWindow $restoreWindow,
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
            occurredAt: TransactionMoment::parse($request->occurredAt, $request->timezone ?? $user->getTimezone()),
            amount: $this->resolveAmount($request, $user, $account, $instrument),
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
        $transaction->setAmount($this->resolveAmount($request, $transaction->getUser(), $account, $instrument));
        $transaction->setFees($request->fees);
        $transaction->setTaxes($request->taxes);
        $transaction->setOccurredAt(TransactionMoment::parse($request->occurredAt, $request->timezone ?? $transaction->getTimezone()));
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

    /** Brings back a trade deleted moments ago ("Undo"), unless its account or instrument was deleted since. */
    public function restore(User $user, string $id): InvestmentTransaction
    {
        $transaction = $this->restoreWindow->withDeleted(function () use ($user, $id) {
            $transaction = $this->transactionRepository->findOneByUserAndId($user, $id);
            $this->restoreWindow->assertRestorable($transaction, [$transaction?->getAccount(), $transaction?->getInstrument()]);
            \assert($transaction !== null);

            return $transaction;
        });

        $transaction->restore();
        $this->auditLogService->logRestored($transaction, $user);
        $this->entityManager->flush();

        return $transaction;
    }

    /**
     * The amount the user entered (what the broker charged, in the account's
     * currency), or quantity x unit price when left out: converted from the
     * instrument's currency at the day's rate when the account is in another.
     */
    private function resolveAmount(CreateInvestmentTransactionRequest $request, User $user, Account $account, InvestmentInstrument $instrument): string
    {
        if ($request->amount !== null && $request->amount !== '') {
            return bcadd($request->amount, '0', 4);
        }

        $gross = InvestmentTransaction::grossAmount($request->quantity, $request->unitPrice);
        $quote = $instrument->getCurrency();
        $accountCurrency = $account->getCurrency()?->getCode();
        if ($accountCurrency === null || $accountCurrency === $quote) {
            return $gross;
        }

        $day = TransactionMoment::parse($request->occurredAt, $request->timezone ?? $user->getTimezone())->format('Y-m-d');
        $converted = $this->converter->tableFor($user, [$quote, $accountCurrency], new \DateTimeImmutable($day), new \DateTimeImmutable($day))
            ->convertTo4($gross, $quote, $accountCurrency, $day);

        return $converted ?? throw new ApiException(ErrorCode::AmountRequiredOtherCurrency, ['instrument' => $quote, 'account' => $accountCurrency]);
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
