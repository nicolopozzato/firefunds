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
use App\Repository\TransactionRepository;
use App\Core\Doctrine\RestoreWindow;
use App\Transaction\DTO\BulkTransactionRequest;
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
        private readonly TransactionRepository $transactionRepository,
        private readonly RestoreWindow $restoreWindow,
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

    /**
     * One change to several of the user's money transactions (a list's
     * selection), each recorded in its history like a single edit. All of
     * them or none: an id that isn't theirs refuses the whole request.
     *
     * @return int how many were changed
     */
    public function bulk(User $user, BulkTransactionRequest $request): int
    {
        $transactions = $this->transactionRepository->findBy(['user' => $user, 'id' => array_values(array_unique($request->ids))]);
        if (\count($transactions) !== \count(array_unique($request->ids))) {
            throw ApiException::notFound(ErrorCode::TransactionNotFound);
        }

        $category = null;
        if ($request->action === 'category' && $request->categoryId !== null) {
            $category = $this->categoryRepository->findOneByUserAndId($user, $request->categoryId)
                ?? throw ApiException::notFound(ErrorCode::CategoryNotFound);
        }
        $tag = null;
        if ($request->action === 'tag') {
            $tag = $this->tagRepository->findOneByUserAndId($user, (string) $request->tagId)
                ?? throw ApiException::notFound(ErrorCode::TagNotFound);
        }

        foreach ($transactions as $transaction) {
            if ($request->action === 'delete') {
                $transaction->markDeleted();
                $this->auditLogService->logDeleted($transaction, $user);
                continue;
            }
            $before = $this->auditLogService->snapshot($transaction);
            if ($tag !== null) {
                if ($transaction->getTags()->contains($tag)) {
                    continue;
                }
                $transaction->addTag($tag);
            } else {
                $transaction->setCategory($category);
            }
            $this->auditLogService->logUpdated($transaction, $user, $before);
        }
        $this->entityManager->flush();

        return \count($transactions);
    }

    /**
     * Brings back a transaction deleted moments ago ("Undo"), unless one of
     * its accounts was deleted since. A category or tag deleted meanwhile is
     * left off, as the delete took it off the transactions still there.
     */
    public function restore(User $user, string $id): Transaction
    {
        $transaction = $this->restoreWindow->withDeleted(function () use ($user, $id) {
            $transaction = $this->transactionRepository->findOneByUserAndId($user, $id);
            $this->restoreWindow->assertRestorable($transaction, [$transaction?->getSourceAccount(), $transaction?->getDestinationAccount()]);
            \assert($transaction !== null);

            if ($transaction->getCategory()?->getDeletedAt() !== null) {
                $transaction->setCategory(null);
            }
            foreach ($transaction->getTags()->toArray() as $tag) {
                if ($tag->getDeletedAt() !== null) {
                    $transaction->removeTag($tag);
                }
            }

            return $transaction;
        });

        $transaction->restore();
        $this->auditLogService->logRestored($transaction, $user);
        $this->entityManager->flush();

        return $transaction;
    }

    private function applyOptionalFields(Transaction $transaction, User $user, CreateTransactionRequest $request): void
    {
        $transaction->setDescription($request->description);
        $transaction->setDestinationAmount($this->destinationAmount($transaction, $request));
        // A transfer has its own second amount (destinationAmount): an "original amount" makes no sense there.
        $isTransfer = $transaction->getSourceAccount()?->getType() === AccountType::Asset
            && $transaction->getDestinationAccount()?->getType() === AccountType::Asset;
        $transaction->setForeignAmount($isTransfer ? null : $request->foreignAmount);
        $transaction->setForeignCurrency($isTransfer || $request->foreignCurrency === null ? null : strtoupper($request->foreignCurrency));
        $transaction->setNotes($request->notes);

        [$category, $tags] = $this->resolveClassification($user, $request);
        $transaction->setCategory($category);
        foreach ($tags as $tag) {
            $transaction->addTag($tag);
        }
    }

    /**
     * The category and tags a request names, by id or by a new name (the
     * user's with that name, or a new one): for a transaction, and for the
     * template of a recurring one.
     *
     * @return array{0: ?Category, 1: list<Tag>}
     */
    public function resolveClassification(User $user, CreateTransactionRequest $request): array
    {
        $category = null;
        if ($request->hasName($request->newCategoryName)) {
            $name = trim($request->newCategoryName);
            $category = $this->categoryRepository->findOneByUserAndName($user, $name) ?? $this->persisted(new Category($user, $name));
        } elseif ($request->categoryId !== null) {
            $category = $this->categoryRepository->findOneByUserAndId($user, $request->categoryId);

            if ($category === null) {
                throw new ApiException(ErrorCode::CategoryNotFound);
            }
        }

        $tags = [];
        foreach ($request->tagIds as $tagId) {
            $tag = $this->tagRepository->findOneByUserAndId($user, $tagId);

            if ($tag === null) {
                throw new ApiException(ErrorCode::TagNotFound);
            }

            $tags[] = $tag;
        }

        foreach (array_unique(array_map('trim', $request->newTagNames)) as $name) {
            $tags[] = $this->tagRepository->findOneByUserAndName($user, $name) ?? $this->persisted(new Tag($user, $name));
        }

        return [$category, $tags];
    }

    /**
     * The two sides a request names, checked as a transaction's are (a valid
     * pair, an asset side with a currency): for the template of a recurring
     * transaction, which must be one that can be created later.
     *
     * @return array{0: ?Account, 1: ?Account}
     */
    public function resolveSides(User $user, CreateTransactionRequest $request): array
    {
        $source = $this->resolveSide($user, $request->sourceAccountId, $request->newSourceAccountName, AccountType::Revenue);
        $destination = $this->resolveSide($user, $request->destinationAccountId, $request->newDestinationAccountName, AccountType::Expense);

        $this->assertValidAccountPair($source, $destination);

        if ($this->assetSide($source, $destination)->getCurrency() === null) {
            throw new ApiException(ErrorCode::AccountWithoutCurrency);
        }

        return [$source, $destination];
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

    /**
     * What arrived, for a transfer between accounts in two currencies (the
     * rate between them is the two amounts); null for anything else.
     */
    private function destinationAmount(Transaction $transaction, CreateTransactionRequest $request): ?string
    {
        $source = $transaction->getSourceAccount();
        $destination = $transaction->getDestinationAccount();
        if ($source?->getType() !== AccountType::Asset || $destination?->getType() !== AccountType::Asset
            || $source->getCurrency()?->getCode() === $destination->getCurrency()?->getCode()) {
            return null;
        }

        if ($request->destinationAmount === null || $request->destinationAmount === '') {
            throw new ApiException(ErrorCode::DestinationAmountRequired, [
                'source' => $source->getCurrency()?->getCode() ?? '—',
                'destination' => $destination->getCurrency()?->getCode() ?? '—',
            ]);
        }

        return bcadd($request->destinationAmount, '0', 4);
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
