<?php

namespace App\CsvImport\Service;

use App\Account\DTO\CreateAccountRequest;
use App\Account\Service\AccountAuditLogService;
use App\Account\Service\AccountService;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\CsvImport\DTO\CommitImportRequest;
use App\Entity\Account;
use App\Entity\AccountAdjustment;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Entity\Category;
use App\Entity\ImportBatch;
use App\Entity\RecurringTransaction;
use App\Entity\Tag;
use App\Entity\Transaction;
use App\Entity\User;
use App\Repository\AccountRepository;
use App\Repository\CategoryRepository;
use App\Repository\RecurringTransactionRepository;
use App\Repository\TagRepository;
use App\Repository\TransactionRepository;
use App\Transaction\Service\DescriptionHistory;
use App\Transaction\Service\TransactionPayloads;
use App\Transaction\Service\TransactionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * A file (a bank's statement, a spreadsheet of several accounts, a Firefly
 * III export), after the browser read it: what each row is likely to be
 * (preview), then what the user kept recorded as one import, all or none
 * (commit), which can be undone later (`ImportUndo`).
 *
 * The preview looks at the whole file at once, never a query per row: each
 * account's transactions over the file's days (a row already recorded is a
 * duplicate: same direction, same amount, within a day), the recurring
 * transactions on that account (an occurrence expected within 7 days, of
 * the same amount or naming its other side), and the user's history (the
 * latest transaction with the same words gives the other side and the
 * category; an account named as the file's counterparty column wins).
 */
class CsvImportService
{
    private const RECURRING_DAYS = 7;

    /** @var array{accounts: list<string>, categories: list<string>, tags: list<string>} what the import being committed created */
    private array $created = ['accounts' => [], 'categories' => [], 'tags' => []];

    /** @var array<string, array<string, string>> kind => lower-case name => id, found or created by the import being committed */
    private array $named = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountRepository $accounts,
        private readonly CategoryRepository $categories,
        private readonly TagRepository $tags,
        private readonly TransactionRepository $transactions,
        private readonly RecurringTransactionRepository $recurring,
        private readonly TransactionService $transactionService,
        private readonly TransactionPayloads $payloads,
        private readonly DescriptionHistory $history,
        private readonly AccountService $accountService,
        private readonly AccountAuditLogService $accountAuditLog,
    ) {
    }

    public function ownedAssetAccount(User $user, string $id): Account
    {
        $account = $this->accounts->findOneByUserAndId($user, $id);
        if ($account === null || $account->getType() !== AccountType::Asset) {
            throw ApiException::notFound(ErrorCode::AccountNotFound);
        }

        return $account;
    }

    /**
     * @param list<array{date: string, amount: string, accountId?: ?string, description?: ?string, counterparty?: ?string}> $rows
     * @return list<array<string, mixed>> one per row, in order
     */
    public function preview(User $user, array $rows): array
    {
        // Per account the file names: its transactions and expected occurrences over the file's days.
        $days = [];
        foreach ($rows as $row) {
            if (($row['accountId'] ?? null) !== null) {
                $days[strtolower($row['accountId'])][] = $row['date'];
            }
        }
        $accounts = [];
        $existing = [];
        $occurrences = [];
        foreach ($days as $id => $dates) {
            $account = $accounts[$id] = $this->ownedAssetAccount($user, $id);
            $from = new \DateTimeImmutable(min($dates));
            $to = new \DateTimeImmutable(max($dates));
            $existing[$id] = $this->transactions->findOnAccountBetween($account, $from->modify('-1 day'), $to->modify('+1 day'));
            $occurrences[$id] = $this->expectedOccurrences($account, $from->modify('-'.self::RECURRING_DAYS.' days'), $to->modify('+'.self::RECURRING_DAYS.' days'));
        }
        $history = $this->history->forUser($user);
        $byName = $this->accountsByName($user);

        $used = [];
        $result = [];
        foreach ($rows as $index => $row) {
            $id = ($row['accountId'] ?? null) !== null ? strtolower($row['accountId']) : null;
            $account = $id !== null ? $accounts[$id] : null;
            $out = str_starts_with($row['amount'], '-');
            $amount = bcadd(ltrim($row['amount'], '-'), '0', 4);
            $day = new \DateTimeImmutable($row['date']);
            $description = trim((string) ($row['description'] ?? ''));
            $counterparty = trim((string) ($row['counterparty'] ?? ''));

            $duplicate = $account !== null ? $this->takeDuplicate($existing[$id], $used, $account, $out, $amount, $day) : null;
            $occurrence = $account !== null && $duplicate === null ? $this->takeOccurrence($occurrences[$id], $out, $amount, $day, $description.' '.$counterparty) : null;

            $suggestion = null;
            if ($counterparty !== '' && isset($byName[mb_strtolower($counterparty)])) {
                $suggestion = ['account' => $byName[mb_strtolower($counterparty)], 'categoryId' => null];
            }
            $known = $history[DescriptionHistory::key($description)] ?? ($counterparty !== '' ? ($history[DescriptionHistory::key($counterparty)] ?? null) : null);
            if ($known !== null) {
                $other = $out ? $known['destination'] : $known['source'];
                $suggestion = [
                    'account' => $suggestion['account'] ?? ($other !== null && ($other['id'] ?? null) !== $id ? $other : null),
                    'categoryId' => $known['categoryId'],
                ];
            }

            $result[] = [
                'index' => $index,
                'duplicate' => $duplicate !== null ? [
                    'id' => (string) $duplicate->getId(),
                    'description' => $duplicate->getDescription(),
                    'occurredAt' => $duplicate->getOccurredAt()->format('Y-m-d\TH:i:s'),
                ] : null,
                'recurring' => $occurrence !== null ? [
                    'id' => (string) $occurrence[0]->getId(),
                    'description' => $occurrence[0]->getDescription(),
                    'day' => $occurrence[1]->format('Y-m-d'),
                    'accountId' => (string) ($out ? $occurrence[0]->getDestinationAccount()?->getId() : $occurrence[0]->getSourceAccount()?->getId()),
                    'categoryId' => $occurrence[0]->getCategory()?->getId()->toRfc4122(),
                ] : null,
                'suggestion' => $suggestion,
            ];
        }

        return $result;
    }

    /**
     * Everything kept, recorded as one import, all or none: the new asset
     * accounts first, then the payees, categories and tags the rows name
     * (found by name, else created once: the rows are flushed together, so
     * the transaction service can't find what an earlier row created), the
     * transactions (a recurring occurrence linked to its recurrence, which
     * then counts it as dealt with) and the balance adjustments.
     */
    public function commit(User $user, CommitImportRequest $request): ImportBatch
    {
        return $this->entityManager->wrapInTransaction(function () use ($user, $request): ImportBatch {
            $batch = new ImportBatch($user, trim($request->fileName));
            $this->entityManager->persist($batch);
            $this->created = ['accounts' => [], 'categories' => [], 'tags' => []];
            $this->named = [];

            $keys = [];
            foreach ($request->accounts as $new) {
                $account = $this->accountService->create($user, new CreateAccountRequest(
                    type: AccountType::Asset->value,
                    name: trim($new['name']),
                    currencyCode: strtoupper($new['currencyCode']),
                    openingBalance: $new['openingBalance'] ?? null,
                    openingBalanceDate: ($new['openingBalance'] ?? null) !== null ? ($new['openingBalanceDate'] ?? null) : null,
                    assetKind: AssetAccountKind::Checking->value,
                ));
                $keys[$new['key']] = $account;
                $this->created['accounts'][] = (string) $account->getId();
            }

            $parts = [];
            foreach ($request->rows as $index => $row) {
                $part = $row['transaction'];
                foreach (['source' => 'sourceAccountKey', 'destination' => 'destinationAccountKey'] as $side => $field) {
                    if (($row[$field] ?? null) !== null) {
                        $part[$side.'AccountId'] = (string) $this->byKey($keys, $row[$field], "rows[{$index}].{$field}")->getId();
                    }
                }
                $parts[] = $this->resolveNames($user, $part);
            }
            $this->entityManager->flush();

            $requests = $this->payloads->read($parts, 'rows');
            foreach ($requests as $index => $transactionRequest) {
                $transaction = $this->transactionService->create($user, $transactionRequest, flush: false);
                $transaction->setImportBatch($batch);
                $recurringId = $request->rows[$index]['recurringId'] ?? null;
                if ($recurringId !== null) {
                    $recurring = $this->recurring->findOneByUserAndId($user, $recurringId);
                    if ($recurring !== null) {
                        $transaction->setRecurring($recurring);
                        $recurring->handleThrough(new \DateTimeImmutable($request->rows[$index]['recurringDay'] ?? $transaction->getOccurredAt()->format('Y-m-d')));
                    }
                }
            }

            foreach ($request->adjustments as $index => $data) {
                $account = ($data['accountKey'] ?? null) !== null
                    ? $this->byKey($keys, $data['accountKey'], "adjustments[{$index}].accountKey")
                    : $this->ownedAssetAccount($user, (string) ($data['accountId'] ?? ''));
                $description = trim((string) ($data['description'] ?? '')) ?: null;
                $adjustment = new AccountAdjustment($account, new \DateTimeImmutable($data['date']), bcadd($data['amount'], '0', 4), $description);
                $this->entityManager->persist($adjustment->setImportBatch($batch));
            }

            $batch->setCounts(\count($requests), \count($request->adjustments));
            $batch->setCreated($this->created['accounts'], $this->created['categories'], $this->created['tags']);
            $this->entityManager->flush();

            return $batch;
        });
    }

    /**
     * A row's payload with the names it writes (a new payee, category, tags)
     * turned into ids, found or created once per import. A name too long is
     * left for validation to refuse.
     *
     * @param array<string, mixed> $part
     * @return array<string, mixed>
     */
    private function resolveNames(User $user, array $part): array
    {
        foreach (['source' => AccountType::Revenue, 'destination' => AccountType::Expense] as $side => $type) {
            $name = $this->usableName($part['new'.ucfirst($side).'AccountName'] ?? null);
            if ($name !== null && ($part[$side.'AccountId'] ?? null) === null) {
                $part[$side.'AccountId'] = $this->named($type->value, $name, function () use ($user, $type, $name): string {
                    $account = $this->accounts->findOneByUserTypeAndName($user, $type, $name);
                    if ($account === null) {
                        $this->entityManager->persist($account = new Account($user, $type, $name));
                        $this->accountAuditLog->logCreated($account, $user);
                        $this->created['accounts'][] = (string) $account->getId();
                    }

                    return (string) $account->getId();
                });
                unset($part['new'.ucfirst($side).'AccountName']);
            }
        }

        $name = $this->usableName($part['newCategoryName'] ?? null);
        if ($name !== null && ($part['categoryId'] ?? null) === null) {
            $part['categoryId'] = $this->named('category', $name, function () use ($user, $name): string {
                $category = $this->categories->findOneByUserAndName($user, $name);
                if ($category === null) {
                    $this->entityManager->persist($category = new Category($user, $name));
                    $this->created['categories'][] = $category->getId()->toRfc4122();
                }

                return $category->getId()->toRfc4122();
            });
            unset($part['newCategoryName']);
        }

        if (\is_array($part['newTagNames'] ?? null)) {
            $left = [];
            $tagIds = \is_array($part['tagIds'] ?? null) ? $part['tagIds'] : [];
            foreach ($part['newTagNames'] as $tagName) {
                $name = $this->usableName($tagName);
                if ($name === null) {
                    $left[] = $tagName;
                    continue;
                }
                $tagIds[] = $this->named('tag', $name, function () use ($user, $name): string {
                    $tag = $this->tags->findOneByUserAndName($user, $name);
                    if ($tag === null) {
                        $this->entityManager->persist($tag = new Tag($user, $name));
                        $this->created['tags'][] = $tag->getId()->toRfc4122();
                    }

                    return $tag->getId()->toRfc4122();
                });
            }
            $part['tagIds'] = array_values(array_unique($tagIds));
            $part['newTagNames'] = $left;
        }

        return $part;
    }

    /** @param callable(): string $find */
    private function named(string $kind, string $name, callable $find): string
    {
        return $this->named[$kind][mb_strtolower($name)] ??= $find();
    }

    private function usableName(mixed $name): ?string
    {
        if (!\is_string($name)) {
            return null;
        }
        $name = trim($name);

        return $name !== '' && mb_strlen($name) <= 255 ? $name : null;
    }

    /** @param array<string, Account> $keys */
    private function byKey(array $keys, string $key, string $path): Account
    {
        return $keys[$key] ?? throw new BadRequestHttpException(\sprintf('%s is not one of the new accounts.', $path));
    }

    /**
     * @param list<Transaction> $existing
     * @param array<string, true> $used
     */
    private function takeDuplicate(array $existing, array &$used, Account $account, bool $out, string $amount, \DateTimeImmutable $day): ?Transaction
    {
        foreach ($existing as $transaction) {
            $id = (string) $transaction->getId();
            $fromHere = $transaction->getSourceAccount()?->getId()->equals($account->getId()) ?? false;
            if (isset($used[$id]) || $fromHere !== $out || bccomp($transaction->getAmount(), $amount, 4) !== 0) {
                continue;
            }
            $gap = abs((int) (new \DateTimeImmutable($transaction->getOccurredAt()->format('Y-m-d')))->diff($day)->format('%r%a'));
            if ($gap <= 1) {
                $used[$id] = true;

                return $transaction;
            }
        }

        return null;
    }

    /**
     * @param list<array{0: RecurringTransaction, 1: \DateTimeImmutable, 2: bool, 3: bool}> $occurrences [recurrence, day, out, taken]
     * @return array{0: RecurringTransaction, 1: \DateTimeImmutable}|null
     */
    private function takeOccurrence(array &$occurrences, bool $out, string $amount, \DateTimeImmutable $day, string $text): ?array
    {
        $text = mb_strtolower($text);
        foreach ($occurrences as $i => [$recurring, $occurrenceDay, $occurrenceOut, $taken]) {
            if ($taken || $occurrenceOut !== $out || abs((int) $occurrenceDay->diff($day)->format('%r%a')) > self::RECURRING_DAYS) {
                continue;
            }
            $other = $out ? $recurring->getDestinationAccount() : $recurring->getSourceAccount();
            $named = ($other !== null && str_contains($text, mb_strtolower($other->getName())))
                || str_contains($text, mb_strtolower($recurring->getDescription()));
            if ($named || bccomp($recurring->amountOn($occurrenceDay), $amount, 2) === 0) {
                $occurrences[$i][3] = true;

                return [$recurring, $occurrenceDay];
            }
        }

        return null;
    }

    /**
     * Occurrences of the account's recurring transactions not dealt with yet, in the file's days.
     *
     * @return list<array{0: RecurringTransaction, 1: \DateTimeImmutable, 2: bool, 3: bool}>
     */
    private function expectedOccurrences(Account $account, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $occurrences = [];
        foreach ($this->recurring->findByUser($account->getUser()) as $recurring) {
            if ($recurring->isPaused() || !$recurring->getAssetAccount()->getId()->equals($account->getId())) {
                continue;
            }
            $out = $recurring->getSourceAccount()?->getId()->equals($account->getId()) ?? false;
            $start = max($from, $recurring->getHandledThrough()->modify('+1 day'));
            foreach ($recurring->rule()->between($start, $to) as $day) {
                $occurrences[] = [$recurring, $day, $out, false];
            }
        }

        return $occurrences;
    }

    /** @return array<string, array{id: string, name: string, type: string}> lower-case name => account */
    private function accountsByName(User $user): array
    {
        $byName = [];
        foreach ([AccountType::Expense, AccountType::Revenue, AccountType::Asset] as $type) {
            foreach ($this->accounts->findByUserAndType($user, $type) as $account) {
                $byName[mb_strtolower($account->getName())] ??= ['id' => (string) $account->getId(), 'name' => $account->getName(), 'type' => $type->value];
            }
        }

        return $byName;
    }
}
