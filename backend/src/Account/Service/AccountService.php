<?php

namespace App\Account\Service;

use App\Account\DTO\CreateAccountRequest;
use App\Account\DTO\UpdateAccountRequest;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Entity\Account;
use App\Entity\AccountAdjustment;
use App\Entity\AccountType;
use App\Entity\User;
use App\Repository\AccountAdjustmentRepository;
use App\Currency\Service\ExchangeRateService;
use App\Repository\AccountRepository;
use App\Repository\CurrencyRepository;
use Doctrine\ORM\EntityManagerInterface;

class AccountService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CurrencyRepository $currencyRepository,
        private readonly AccountRepository $accountRepository,
        private readonly AccountAdjustmentRepository $accountAdjustmentRepository,
        private readonly AccountAuditLogService $auditLog,
        private readonly ExchangeRateService $exchangeRates,
    ) {
    }

    /**
     * Shows on the dashboard exactly these asset accounts of the user's and
     * hides every other one. Ids of other users' accounts are ignored.
     *
     * @param string[] $visibleAccountIds
     */
    public function setDashboardAccounts(User $user, array $visibleAccountIds): void
    {
        $visible = array_flip(array_map('strtolower', $visibleAccountIds));

        foreach ($this->accountRepository->findByUserAndType($user, AccountType::Asset) as $account) {
            $account->setShowOnDashboard(isset($visible[$account->getId()->toRfc4122()]));
        }

        $this->entityManager->flush();
    }

    /**
     * Moves the account right after or right before another account of the
     * same type, then numbers every account of that type in the new order
     * (inactive ones included), so their order is fully stored from then on.
     */
    public function move(Account $account, ?string $afterId, ?string $beforeId): void
    {
        $neighbourId = strtolower((string) ($afterId ?? $beforeId));
        if ($neighbourId === $account->getId()->toRfc4122()) {
            return;
        }

        $others = array_values(array_filter(
            $this->accountRepository->findByUserAndType($account->getUser(), $account->getType()),
            static fn (Account $other) => !$other->getId()->equals($account->getId()),
        ));

        $neighbourIndex = null;
        foreach ($others as $index => $other) {
            if ($other->getId()->toRfc4122() === $neighbourId) {
                $neighbourIndex = $index;
                break;
            }
        }
        if ($neighbourIndex === null) {
            throw new ApiException(ErrorCode::AccountMoveTypeMismatch);
        }

        array_splice($others, $afterId !== null ? $neighbourIndex + 1 : $neighbourIndex, 0, [$account]);
        foreach ($others as $position => $other) {
            $other->setPosition($position);
        }

        $this->entityManager->flush();
    }

    public function create(User $user, CreateAccountRequest $request): Account
    {
        $type = $request->getAccountType();
        $account = new Account($user, $type, $request->name);

        $this->applyCommonFields($account, $request->iban, $request->bic, $request->accountNumber, $request->notes, $request->isActive);

        if ($type === AccountType::Asset) {
            $this->applyAssetFields($account, $request->currencyCode, $request->openingBalance, $request->openingBalanceDate);
            $account->setAssetKind($request->getAssetAccountKind());
            $this->applyAdjustments($account, $request->adjustments);
        }

        $this->entityManager->persist($account);
        $this->auditLog->logCreated($account, $user);
        $this->entityManager->flush();
        $this->exchangeRates->syncForUser($user);

        return $account;
    }

    public function update(Account $account, UpdateAccountRequest $request): Account
    {
        $adjustments = $this->accountAdjustmentRepository->findByAccount($account);
        $before = $this->auditLog->snapshot($account, $adjustments);

        $account->setName($request->name);
        $this->applyCommonFields($account, $request->iban, $request->bic, $request->accountNumber, $request->notes, $request->isActive);

        if ($account->getType() === AccountType::Asset) {
            $this->assertCurrencyChangeAllowed($account, $request->currencyCode);
            $this->applyAssetFields($account, $request->currencyCode, $request->openingBalance, $request->openingBalanceDate);
            $adjustments = $this->applyAdjustments($account, $request->adjustments) ?? $adjustments;
        }

        $this->auditLog->logUpdated($account, $account->getUser(), $before, $adjustments);
        $this->entityManager->flush();
        $this->exchangeRates->syncForUser($account->getUser());

        return $account;
    }

    /**
     * Makes the account's other changes (AccountAdjustment) the ones sent:
     * those with the id of one of them update it, the others are created,
     * and the ones not sent any more are deleted. Null changes nothing.
     *
     * @param ?array<array{id?: ?string, date: string, amount: string, description?: ?string}> $adjustments
     * @return ?AccountAdjustment[] the account's adjustments now; null when nothing was sent
     */
    private function applyAdjustments(Account $account, ?array $adjustments): ?array
    {
        if ($adjustments === null) {
            return null;
        }

        $existing = [];
        $kept = [];
        foreach ($this->accountAdjustmentRepository->findByAccount($account) as $adjustment) {
            $existing[$adjustment->getId()->toRfc4122()] = $adjustment;
        }

        foreach ($adjustments as $data) {
            $date = new \DateTimeImmutable($data['date']);
            $amount = bcadd($data['amount'], '0', 4);
            $description = trim((string) ($data['description'] ?? '')) ?: null;
            $id = strtolower((string) ($data['id'] ?? ''));

            if (isset($existing[$id])) {
                $kept[] = $existing[$id]->setDate($date)->setAmount($amount)->setDescription($description);
                unset($existing[$id]);
                continue;
            }

            $kept[] = $created = new AccountAdjustment($account, $date, $amount, $description);
            $this->entityManager->persist($created);
        }

        foreach ($existing as $removed) {
            $removed->markDeleted();
        }

        return $kept;
    }

    /**
     * Every amount on an account is expressed in its currency, so once it has
     * transactions switching currency would silently turn e.g. 1,000 EUR into
     * 1,000 USD. Locked until then, like the asset kind is from the start.
     */
    private function assertCurrencyChangeAllowed(Account $account, ?string $currencyCode): void
    {
        if ($currencyCode === null || strtoupper($currencyCode) === $account->getCurrency()?->getCode()) {
            return;
        }

        if ($this->accountRepository->getLastActivityDates([$account]) !== []) {
            throw new ApiException(ErrorCode::AccountCurrencyLocked);
        }
    }

    private function applyCommonFields(
        Account $account,
        ?string $iban,
        ?string $bic,
        ?string $accountNumber,
        ?string $notes,
        bool $isActive,
    ): void {
        $account->setIban($iban);
        $account->setBic($bic);
        $account->setAccountNumber($accountNumber);
        $account->setNotes($notes);
        $account->setIsActive($isActive);
    }

    private function applyAssetFields(
        Account $account,
        ?string $currencyCode,
        ?string $openingBalance,
        ?string $openingBalanceDate,
    ): void {
        if ($currencyCode !== null) {
            $currency = $this->currencyRepository->findOneByCode($currencyCode);

            if ($currency === null) {
                throw new ApiException(ErrorCode::CurrencyNotAvailable, ['currency' => $currencyCode]);
            }

            $account->setCurrency($currency);
        }

        $account->setOpeningBalance($openingBalance ?? '0.0000');
        $account->setOpeningBalanceDate(
            $openingBalanceDate !== null ? new \DateTimeImmutable($openingBalanceDate) : null
        );
    }
}
