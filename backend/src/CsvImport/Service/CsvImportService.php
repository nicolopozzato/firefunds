<?php

namespace App\CsvImport\Service;

use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\CsvImport\DTO\CommitImportRequest;
use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\RecurringTransaction;
use App\Entity\Transaction;
use App\Entity\User;
use App\Repository\AccountRepository;
use App\Repository\RecurringTransactionRepository;
use App\Repository\TransactionRepository;
use App\Transaction\Service\TransactionPayloads;
use App\Transaction\Service\TransactionService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A bank's file, after the browser read it: what each row is likely to be
 * (preview), then the rows the user kept recorded, all or none (commit).
 *
 * The preview looks at the whole file at once, never a query per row: the
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
    private const HISTORY_YEARS = 3;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountRepository $accounts,
        private readonly TransactionRepository $transactions,
        private readonly RecurringTransactionRepository $recurring,
        private readonly TransactionService $transactionService,
        private readonly TransactionPayloads $payloads,
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
     * @param list<array{date: string, amount: string, description?: ?string, counterparty?: ?string}> $rows
     * @return list<array<string, mixed>> one per row, in order
     */
    public function preview(User $user, Account $account, array $rows): array
    {
        $days = array_map(static fn (array $row) => $row['date'], $rows);
        $from = new \DateTimeImmutable(min($days));
        $to = new \DateTimeImmutable(max($days));
        $existing = $this->transactions->findOnAccountBetween($account, $from->modify('-1 day'), $to->modify('+1 day'));
        $occurrences = $this->expectedOccurrences($account, $from->modify('-'.self::RECURRING_DAYS.' days'), $to->modify('+'.self::RECURRING_DAYS.' days'));
        $history = $this->history($user);
        $byName = $this->accountsByName($user);

        $used = [];
        $result = [];
        foreach ($rows as $index => $row) {
            $out = str_starts_with($row['amount'], '-');
            $amount = bcadd(ltrim($row['amount'], '-'), '0', 4);
            $day = new \DateTimeImmutable($row['date']);
            $description = trim((string) ($row['description'] ?? ''));
            $counterparty = trim((string) ($row['counterparty'] ?? ''));

            $duplicate = $this->takeDuplicate($existing, $used, $account, $out, $amount, $day);
            $occurrence = $duplicate === null ? $this->takeOccurrence($occurrences, $out, $amount, $day, $description.' '.$counterparty) : null;

            $suggestion = null;
            if ($counterparty !== '' && isset($byName[mb_strtolower($counterparty)])) {
                $suggestion = ['account' => $byName[mb_strtolower($counterparty)], 'categoryId' => null];
            }
            $known = $history[self::key($description)] ?? ($counterparty !== '' ? ($history[self::key($counterparty)] ?? null) : null);
            if ($known !== null) {
                $other = $out ? $known['destination'] : $known['source'];
                $suggestion = [
                    'account' => $suggestion['account'] ?? ($other !== null && ($other['id'] ?? null) !== (string) $account->getId() ? $other : null),
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
     * The rows become transactions, all or none; a recurring occurrence is
     * linked to its recurrence, which then counts it as dealt with.
     *
     * @return int how many were recorded
     */
    public function commit(User $user, CommitImportRequest $request): int
    {
        $requests = $this->payloads->read(array_map(static fn (array $row) => $row['transaction'], $request->rows), 'rows');

        return $this->entityManager->wrapInTransaction(function () use ($user, $request, $requests): int {
            foreach ($requests as $index => $transactionRequest) {
                $transaction = $this->transactionService->create($user, $transactionRequest);
                $recurringId = $request->rows[$index]['recurringId'] ?? null;
                if ($recurringId !== null) {
                    $recurring = $this->recurring->findOneByUserAndId($user, $recurringId);
                    if ($recurring !== null) {
                        $transaction->setRecurring($recurring);
                        $recurring->handleThrough(new \DateTimeImmutable($request->rows[$index]['recurringDay'] ?? $transaction->getOccurredAt()->format('Y-m-d')));
                    }
                }
            }
            $this->entityManager->flush();

            return \count($requests);
        });
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

    /**
     * The latest transaction of each description (normalised) in the last
     * years: its two sides and category.
     *
     * @return array<string, array{source: ?array{id: string, name: string, type: string}, destination: ?array{id: string, name: string, type: string}, categoryId: ?string}>
     */
    private function history(User $user): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            <<<SQL
                SELECT DISTINCT ON (lower(t.description)) t.description,
                       s.id AS source_id, s.name AS source_name, s.type AS source_type,
                       d.id AS destination_id, d.name AS destination_name, d.type AS destination_type,
                       t.category_id
                FROM transaction t
                LEFT JOIN account s ON s.id = t.source_account_id AND s.deleted_at IS NULL
                LEFT JOIN account d ON d.id = t.destination_account_id AND d.deleted_at IS NULL
                WHERE t.user_id = :user AND t.deleted_at IS NULL AND t.description IS NOT NULL
                  AND t.occurred_at >= :since
                ORDER BY lower(t.description), t.occurred_at DESC
                SQL,
            ['user' => $user->getId()->toRfc4122(), 'since' => (new \DateTimeImmutable('-'.self::HISTORY_YEARS.' years'))->format('Y-m-d')],
        );

        $history = [];
        foreach ($rows as $row) {
            $key = self::key((string) $row['description']);
            if ($key === '' || isset($history[$key])) {
                continue;
            }
            $side = static fn (string $prefix) => $row[$prefix.'_id'] !== null
                ? ['id' => (string) $row[$prefix.'_id'], 'name' => (string) $row[$prefix.'_name'], 'type' => (string) $row[$prefix.'_type']]
                : null;
            $history[$key] = ['source' => $side('source'), 'destination' => $side('destination'), 'categoryId' => $row['category_id']];
        }

        return $history;
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

    /**
     * What stays of a bank's description once what changes every time is
     * gone: digits, dates, card numbers, punctuation ("POS 12/10 ESSELUNGA
     * MILANO CARTA *1234" and "POS 03/11 ESSELUNGA MILANO CARTA *1234" are
     * the same shop).
     */
    public static function key(string $text): string
    {
        $text = mb_strtolower($text);
        $text = (string) preg_replace('/[^\p{L}\s]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
