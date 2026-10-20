<?php

namespace App\Transaction\Service;

use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Core\Period\TransactionMoment;
use App\Account\Service\AccountAuditLogService;
use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Category;
use App\Entity\Tag;
use App\Entity\Transaction;
use App\Entity\User;
use App\Repository\AccountRepository;
use App\Repository\CategoryRepository;
use App\Repository\TagRepository;
use App\Transaction\DTO\CreateTransactionRequest;
use Doctrine\ORM\EntityManagerInterface;

class TransactionService
{
    /**
     * Allowed source type => destination types. `null` is the "external" side:
     * money leaving the tracked accounts (a withdrawal to nobody in particular)
     * or coming from outside them (an income whose origin doesn't matter).
     */
    private const array VALID_TYPE_PAIRS = [
        AccountType::Asset->value => [AccountType::Expense, AccountType::Asset, null],
        AccountType::Revenue->value => [AccountType::Asset],
        'external' => [AccountType::Asset],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountRepository $accountRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly TagRepository $tagRepository,
        private readonly TransactionAuditLogService $auditLogService,
        private readonly AccountAuditLogService $accountAuditLog,
    ) {
    }

    /**
     * Names the form wrote instead of picking (new sides, category, tags) are
     * found or created here, in the same flush as the transaction: a refused
     * transaction creates nothing, and saving again finds what is there.
     */
    public function create(User $user, CreateTransactionRequest $request): Transaction
    {
        $sourceAccount = $this->resolveSide($user, $request->sourceAccountId, $request->newSourceAccountName, AccountType::Revenue);
        $destinationAccount = $this->resolveSide($user, $request->destinationAccountId, $request->newDestinationAccountName, AccountType::Expense);

        $this->assertValidAccountPair($sourceAccount, $destinationAccount);

        $currency = $this->assetSide($sourceAccount, $destinationAccount)->getCurrency();

        if ($currency === null) {
            throw new ApiException(ErrorCode::AccountWithoutCurrency);
        }

        $occurredAt = TransactionMoment::parse($request->occurredAt, $request->timezone ?? $user->getTimezone());

        $transaction = new Transaction(
            user: $user,
            sourceAccount: $sourceAccount,
            destinationAccount: $destinationAccount,
            occurredAt: $occurredAt,
            amount: $request->amount,
            currency: $currency->getCode(),
        );

        $this->applyOptionalFields($transaction, $user, $request);

        $this->entityManager->persist($transaction);
        $this->auditLogService->logCreated($transaction, $user);
        $this->entityManager->flush();

        return $transaction;
    }

    public function update(Transaction $transaction, CreateTransactionRequest $request): Transaction
    {
        $before = $this->auditLogService->snapshot($transaction);

        $user = $transaction->getUser();
        $sourceAccount = $this->resolveSide($user, $request->sourceAccountId, $request->newSourceAccountName, AccountType::Revenue);
        $destinationAccount = $this->resolveSide($user, $request->destinationAccountId, $request->newDestinationAccountName, AccountType::Expense);

        $this->assertValidAccountPair($sourceAccount, $destinationAccount);

        $currency = $this->assetSide($sourceAccount, $destinationAccount)->getCurrency();

        if ($currency === null) {
            throw new ApiException(ErrorCode::AccountWithoutCurrency);
        }

        $transaction->setSourceAccount($sourceAccount);
        $transaction->setDestinationAccount($destinationAccount);
        $transaction->setOccurredAt(TransactionMoment::parse($request->occurredAt, $request->timezone ?? $transaction->getTimezone()));
        $transaction->setAmount($request->amount);
        $transaction->setCurrency($currency->getCode());
        $transaction->setCategory(null);
        $transaction->clearTags();

        $this->applyOptionalFields($transaction, $transaction->getUser(), $request);

        $this->auditLogService->logUpdated($transaction, $transaction->getUser(), $before);
        $this->entityManager->flush();

        return $transaction;
    }

    public function delete(Transaction $transaction): void
    {
        $transaction->markDeleted();
        $this->auditLogService->logDeleted($transaction, $transaction->getUser());
        $this->entityManager->flush();
    }

    private function applyOptionalFields(Transaction $transaction, User $user, CreateTransactionRequest $request): void
    {
        $transaction->setDescription($request->description);
        $transaction->setForeignAmount($request->foreignAmount);
        $transaction->setForeignCurrency($request->foreignCurrency !== null ? strtoupper($request->foreignCurrency) : null);
        $transaction->setNotes($request->notes);

        if ($request->hasName($request->newCategoryName)) {
            $name = trim($request->newCategoryName);
            $transaction->setCategory($this->categoryRepository->findOneByUserAndName($user, $name) ?? $this->persisted(new Category($user, $name)));
        } elseif ($request->categoryId !== null) {
            $category = $this->categoryRepository->findOneByUserAndId($user, $request->categoryId);

            if ($category === null) {
                throw new ApiException(ErrorCode::CategoryNotFound);
            }

            $transaction->setCategory($category);
        }

        foreach ($request->tagIds as $tagId) {
            $tag = $this->tagRepository->findOneByUserAndId($user, $tagId);

            if ($tag === null) {
                throw new ApiException(ErrorCode::TagNotFound);
            }

            $transaction->addTag($tag);
        }

        foreach (array_unique(array_map('trim', $request->newTagNames)) as $name) {
            $transaction->addTag($this->tagRepository->findOneByUserAndName($user, $name) ?? $this->persisted(new Tag($user, $name)));
        }
    }

    /**
     * A side: the account picked by id, the one named (the user's with that
     * name, or a new one of that type), or null for "external".
     */
    private function resolveSide(User $user, ?string $accountId, ?string $newName, AccountType $typeIfNamed): ?Account
    {
        if ($accountId !== null) {
            return $this->resolveOwnedAccount($user, $accountId);
        }
        if ($newName === null || trim($newName) === '') {
            return null;
        }

        $name = trim($newName);
        $account = $this->accountRepository->findOneByUserTypeAndName($user, $typeIfNamed, $name);
        if ($account === null) {
            $account = $this->persisted(new Account($user, $typeIfNamed, $name));
            $this->accountAuditLog->logCreated($account, $user);
        }

        return $account;
    }

    /**
     * @template T of object
     * @param T $entity
     * @return T
     */
    private function persisted(object $entity): object
    {
        $this->entityManager->persist($entity);

        return $entity;
    }

    /**
     * The asset account of a valid pair, whose currency the transaction takes:
     * the source unless it is a revenue account or external.
     */
    private function assetSide(?Account $source, ?Account $destination): Account
    {
        return $source?->getType() === AccountType::Asset ? $source : $destination;
    }

    private function resolveOwnedAccount(User $user, string $accountId): Account
    {
        $account = $this->accountRepository->findOneByUserAndId($user, $accountId);

        if ($account === null) {
            throw new ApiException(ErrorCode::AccountNotFound);
        }

        return $account;
    }

    private function assertValidAccountPair(?Account $source, ?Account $destination): void
    {
        $allowedDestinations = self::VALID_TYPE_PAIRS[$source?->getType()->value ?? 'external'] ?? null;

        if ($allowedDestinations === null || !\in_array($destination?->getType(), $allowedDestinations, true)) {
            // Account types (asset, expense, revenue), or `none` for no account.
            throw new ApiException(ErrorCode::AccountPairNotAllowed, [
                'source' => $source?->getType()->value ?? 'none',
                'destination' => $destination?->getType()->value ?? 'none',
            ]);
        }
    }
}
