<?php

namespace App\Recurring\Service;

use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Entity\AccountType;
use App\Entity\RecurrenceMode;
use App\Entity\RecurringTransaction;
use App\Entity\Transaction;
use App\Entity\User;
use App\Recurring\DTO\ConfirmOccurrenceRequest;
use App\Recurring\DTO\OccurrenceResponse;
use App\Recurring\DTO\RecurringTransactionRequest;
use App\Recurring\DTO\RecurringTransactionResponse;
use App\Notification\Service\NotificationCenter;
use App\Repository\RecurringTransactionRepository;
use App\Repository\TransactionRepository;
use App\Transaction\DTO\CreateTransactionRequest;
use App\Transaction\Service\TransactionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Recurring transactions: kept from the form, turned into transactions on
 * their day (by the scheduled task when they create themselves, by the user
 * when they wait to be confirmed), and what is coming.
 *
 * One the user enters ("I'll enter it") recognises a transaction like it
 * arriving within MATCH_DAYS of its day (same sides, any amount), entered by
 * hand or imported, and reminds the user when none has come some days after.
 *
 * Only what falls from the day a recurrence is made on is ever created: a
 * start date in the past only sets the rhythm. Each transaction made keeps
 * a link to its recurrence.
 */
class RecurringTransactionService
{
    /** How far from its day a transaction still counts as an occurrence. */
    private const MATCH_DAYS = 7;
    /** How far the user is offered transactions to link by hand. */
    private const LINK_DAYS = 15;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RecurringTransactionRepository $repository,
        private readonly TransactionRepository $transactionRepository,
        private readonly TransactionService $transactionService,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly RecurringAuditLogService $auditLog,
        private readonly NotificationCenter $notifications,
    ) {
    }

    public function create(User $user, RecurringTransactionRequest $request): RecurringTransaction
    {
        $timezone = $request->timezone ?? $user->getTimezone();
        $today = $this->today($timezone);
        $from = $request->fromTransactionId !== null ? $this->ownedTransaction($user, $request->fromTransactionId) : null;
        // Nothing before today; made from a transaction, nothing up to its day either: it is the first.
        $handled = $today->modify('-1 day');
        if ($from !== null && $from->getOccurredAt()->format('Y-m-d') > $handled->format('Y-m-d')) {
            $handled = new \DateTimeImmutable($from->getOccurredAt()->format('Y-m-d'));
        }

        $recurring = new RecurringTransaction($user, trim((string) $request->description), new \DateTimeImmutable($request->startDate), $timezone, $handled);
        $this->apply($recurring, $user, $request);
        $this->entityManager->persist($recurring);
        $this->auditLog->logCreated($recurring, $user);
        $from?->setRecurring($recurring);
        $this->entityManager->flush();

        return $recurring;
    }

    public function update(RecurringTransaction $recurring, RecurringTransactionRequest $request): RecurringTransaction
    {
        $before = $this->auditLog->snapshot($recurring);
        $recurring->setDescription(trim((string) $request->description));
        $this->apply($recurring, $recurring->getUser(), $request);
        $this->auditLog->logUpdated($recurring, $recurring->getUser(), $before);
        if ($recurring->getMode() === RecurrenceMode::Auto) {
            $this->notifications->resolve($this->reminderKey($recurring), true);
        }
        $this->entityManager->flush();

        return $recurring;
    }

    /** Its transactions stay, no longer linked to it. */
    public function delete(RecurringTransaction $recurring): void
    {
        $this->entityManager->createQuery('UPDATE '.Transaction::class.' t SET t.recurring = NULL WHERE t.recurring = :recurring')
            ->setParameter('recurring', $recurring)
            ->execute();
        $recurring->markDeleted();
        $this->auditLog->logDeleted($recurring, $recurring->getUser());
        $this->notifications->resolve($this->reminderKey($recurring), true);
        $this->entityManager->flush();
    }

    public function setPaused(RecurringTransaction $recurring, bool $paused): void
    {
        $before = $this->auditLog->snapshot($recurring);
        if ($paused) {
            $recurring->pause();
            $this->notifications->resolve($this->reminderKey($recurring), true);
        } elseif ($recurring->isPaused()) {
            $recurring->resume($this->today($recurring->getTimezone()));
        }
        $this->auditLog->logUpdated($recurring, $recurring->getUser(), $before);
        $this->entityManager->flush();
    }

    /**
     * Creates every occurrence of the self-creating recurrences whose moment
     * has come (their day at their time, where they happen), catching up on
     * any missed. What the scheduled task runs.
     *
     * @return array{created: int, failed: int}
     */
    public function createDue(): array
    {
        $now = $this->clock->now();
        $created = 0;
        $failed = 0;
        foreach ($this->repository->findRunning() as $recurring) {
            if ($recurring->getMode() !== RecurrenceMode::Auto) {
                continue;
            }
            $days = $recurring->rule()->between($recurring->getHandledThrough()->modify('+1 day'), $this->today($recurring->getTimezone()));
            foreach ($days as $day) {
                if ($recurring->momentOn($day) > $now) {
                    break;
                }
                try {
                    $this->createOccurrence($recurring, $day, null, null);
                    ++$created;
                } catch (\Throwable $error) {
                    // A template that can no longer be created (e.g. an account changed currency): the next ones still go.
                    $this->logger->error('Recurring transaction {id} on {day}: {error}', ['id' => (string) $recurring->getId(), 'day' => $day->format('Y-m-d'), 'error' => $error->getMessage()]);
                    ++$failed;
                    if (!$this->entityManager->isOpen()) {
                        return ['created' => $created, 'failed' => $failed];
                    }
                    break;
                }
            }
        }

        return ['created' => $created, 'failed' => $failed];
    }

    /**
     * The ones the user enters: links each transaction like an occurrence
     * that arrived within MATCH_DAYS of its day (early ones too), in order;
     * the first still missing `reminderDays` after its day is a reminder.
     * What the scheduled task runs, after createDue().
     *
     * @return array{linked: int, reminded: int}
     */
    public function followManual(): array
    {
        $linked = 0;
        $reminded = 0;
        foreach ($this->repository->findRunning() as $recurring) {
            if ($recurring->getMode() !== RecurrenceMode::Confirm) {
                continue;
            }
            $today = $this->today($recurring->getTimezone());
            $used = [];
            $days = $recurring->rule()->between($recurring->getHandledThrough()->modify('+1 day'), $today->modify('+'.self::MATCH_DAYS.' days'));
            foreach ($days as $day) {
                $match = $this->closestMatch($recurring, $day, $used);
                if ($match !== null) {
                    $used[] = $match->getId()->toRfc4122();
                    $this->linkOccurrence($recurring, $day, $match);
                    ++$linked;
                    continue;
                }
                if ($day <= $today->modify('-'.$recurring->getReminderDays().' days')) {
                    $notification = $this->notifications->raise($recurring->getUser(), 'recurring_missing', $this->reminderKey($recurring, $day), [
                        'recurringId' => $recurring->getId()->toRfc4122(),
                        'description' => $recurring->getDescription(),
                        'day' => $day->format('Y-m-d'),
                    ]);
                    if ($this->entityManager->getUnitOfWork()->isScheduledForInsert($notification)) {
                        ++$reminded;
                    }
                }
                break;
            }
        }
        $this->entityManager->flush();

        return ['linked' => $linked, 'reminded' => $reminded];
    }

    /**
     * Transactions the user may link to the first waiting occurrence ("I
     * entered it already"): on its asset account, not part of a recurrence,
     * within LINK_DAYS of its day, nearest first.
     *
     * @return list<Transaction>
     */
    public function linkable(RecurringTransaction $recurring): array
    {
        $day = $this->firstPending($recurring);
        $candidates = $this->transactionRepository->findLinkable(
            $recurring->getUser(),
            $recurring->getAssetAccount(),
            $day->modify('-'.self::LINK_DAYS.' days'),
            $day->modify('+'.self::LINK_DAYS.' days'),
        );
        usort($candidates, static fn (Transaction $a, Transaction $b) => self::distance($a, $day) <=> self::distance($b, $day));

        return $candidates;
    }

    /** "I entered it already": this transaction is the first waiting occurrence. */
    public function link(RecurringTransaction $recurring, string $transactionId): Transaction
    {
        $day = $this->firstPending($recurring);
        $transaction = $this->ownedTransaction($recurring->getUser(), $transactionId);
        $account = $recurring->getAssetAccount();
        $onAccount = static fn (?\App\Entity\Account $side) => $side !== null && $side->getId()->equals($account->getId());
        if ($transaction->getRecurring() !== null || (!$onAccount($transaction->getSourceAccount()) && !$onAccount($transaction->getDestinationAccount()))) {
            throw new ApiException(ErrorCode::TransactionNotLinkable, [], Response::HTTP_CONFLICT);
        }
        $this->linkOccurrence($recurring, $day, $transaction);
        $this->entityManager->flush();

        return $transaction;
    }

    /** The next occurrence of one that waits for the user, created as they say (the amount paid, the day). */
    public function confirm(RecurringTransaction $recurring, ConfirmOccurrenceRequest $request): Transaction
    {
        $day = $this->firstPending($recurring);
        $occurredOn = $request->day !== null ? new \DateTimeImmutable($request->day) : $day;

        return $this->createOccurrence($recurring, $day, $occurredOn, $request->amount);
    }

    /** The next occurrence of one that waits for the user, let go without a transaction. */
    public function skip(RecurringTransaction $recurring): void
    {
        $day = $this->firstPending($recurring);
        $recurring->handleThrough($day);
        $this->notifications->resolve($this->reminderKey($recurring, $day));
        $this->entityManager->flush();
    }

    /** @return list<RecurringTransactionResponse> */
    public function list(User $user): array
    {
        return array_map(fn (RecurringTransaction $recurring) => $this->toResponse($recurring), $this->repository->findByUser($user));
    }

    public function toResponse(RecurringTransaction $recurring): RecurringTransactionResponse
    {
        $today = $this->today($recurring->getTimezone());

        return RecurringTransactionResponse::fromEntity($recurring, \count($this->pendingDays($recurring, $today)), $this->monthlyAmount($recurring, $today));
    }

    /**
     * What is coming over the next days, the user's recurrences together, by
     * day: occurrences not dealt with yet, from today on.
     *
     * @return list<OccurrenceResponse>
     */
    public function upcoming(User $user, int $days): array
    {
        $occurrences = [];
        foreach ($this->repository->findByUser($user) as $recurring) {
            if ($recurring->isPaused()) {
                continue;
            }
            $today = $this->today($recurring->getTimezone());
            $from = max($today, $recurring->getHandledThrough()->modify('+1 day'));
            $response = null;
            foreach ($recurring->rule()->between($from, $today->modify("+{$days} days")) as $day) {
                $response ??= $this->toResponse($recurring);
                $occurrences[] = OccurrenceResponse::of($recurring, $day, $response);
            }
        }
        usort($occurrences, static fn (OccurrenceResponse $a, OccurrenceResponse $b) => [$a->day, $a->time] <=> [$b->day, $b->time]);

        return $occurrences;
    }

    /**
     * Occurrences waiting for the user (mode confirm, due by today), oldest
     * first: only the first of each can be confirmed or skipped.
     *
     * @return list<OccurrenceResponse>
     */
    public function pending(User $user): array
    {
        $occurrences = [];
        foreach ($this->repository->findByUser($user) as $recurring) {
            if ($recurring->isPaused() || $recurring->getMode() !== RecurrenceMode::Confirm) {
                continue;
            }
            $days = $this->pendingDays($recurring, $this->today($recurring->getTimezone()));
            if ($days !== []) {
                $occurrences[] = OccurrenceResponse::of($recurring, $days[0], $this->toResponse($recurring));
            }
        }
        usort($occurrences, static fn (OccurrenceResponse $a, OccurrenceResponse $b) => $a->day <=> $b->day);

        return $occurrences;
    }

    private function apply(RecurringTransaction $recurring, User $user, RecurringTransactionRequest $request): void
    {
        $template = $request->template();
        [$source, $destination] = $this->transactionService->resolveSides($user, $template);
        [$category, $tags] = $this->transactionService->resolveClassification($user, $template);

        $recurring->setAccounts($source, $destination);
        $this->applyAmounts($recurring, $request);
        $recurring->setDestinationAmount($this->destinationAmount($recurring, $request));
        $recurring->setCategory($category);
        $recurring->setTags($tags);
        $recurring->setNotes($request->notes);
        $recurring->setMode($request->mode);
        $recurring->setReminderDays($request->reminderDays);
        $recurring->setSchedule(
            startDate: new \DateTimeImmutable($request->startDate),
            unit: $request->unit,
            interval: $request->interval,
            monthDay: $request->monthDay,
            weekendShift: $request->weekendShift,
            months: $request->normalizedMonths(),
            endDate: $request->endDate !== null ? new \DateTimeImmutable($request->endDate) : null,
            count: $request->count,
            time: $request->time,
        );
    }

    /**
     * The amount and its planned changes. A change whose day has come (today
     * where it happens) becomes the amount: what is planned stays ahead.
     */
    private function applyAmounts(RecurringTransaction $recurring, RecurringTransactionRequest $request): void
    {
        $today = $this->today($request->timezone ?? $recurring->getTimezone())->format('Y-m-d');
        $amount = bcadd($request->amount, '0', 4);
        $planned = [];
        $byDay = [];
        foreach ($request->amountChanges as $change) {
            $byDay[$change['from']] = bcadd($change['amount'], '0', 4);
        }
        ksort($byDay);
        foreach ($byDay as $from => $changed) {
            if ($from <= $today) {
                $amount = $changed;
            } else {
                $planned[] = ['from' => (string) $from, 'amount' => $changed];
            }
        }
        $recurring->setAmount($amount);
        $recurring->setAmountChanges($planned);
    }

    /** As for a transaction: a transfer between two currencies says what arrives, anything else has none. */
    private function destinationAmount(RecurringTransaction $recurring, RecurringTransactionRequest $request): ?string
    {
        $source = $recurring->getSourceAccount();
        $destination = $recurring->getDestinationAccount();
        if ($source?->getType() !== AccountType::Asset || $destination?->getType() !== AccountType::Asset
            || $source->getCurrency()?->getCode() === $destination->getCurrency()?->getCode()) {
            return null;
        }
        if ($request->destinationAmount === null) {
            throw new ApiException(ErrorCode::DestinationAmountRequired, [
                'source' => $source->getCurrency()?->getCode() ?? '—',
                'destination' => $destination->getCurrency()?->getCode() ?? '—',
            ]);
        }

        return bcadd($request->destinationAmount, '0', 4);
    }

    private function createOccurrence(RecurringTransaction $recurring, \DateTimeImmutable $day, ?\DateTimeImmutable $occurredOn, ?string $amount): Transaction
    {
        $transaction = $this->transactionService->create($recurring->getUser(), new CreateTransactionRequest(
            description: $recurring->getDescription(),
            sourceAccountId: $recurring->getSourceAccount()?->getId()->toRfc4122(),
            destinationAccountId: $recurring->getDestinationAccount()?->getId()->toRfc4122(),
            occurredAt: ($occurredOn ?? $day)->format('Y-m-d').'T'.$recurring->getTime().':00',
            amount: $amount !== null ? bcadd($amount, '0', 4) : $recurring->amountOn($day),
            destinationAmount: $recurring->getDestinationAmount(),
            categoryId: $recurring->getCategory()?->getId()->toRfc4122(),
            tagIds: array_values(array_map(static fn ($tag) => $tag->getId()->toRfc4122(), $recurring->getTags()->toArray())),
            notes: $recurring->getNotes(),
            timezone: $recurring->getTimezone(),
        ));
        $transaction->setRecurring($recurring);
        $recurring->handleThrough($day);
        $this->notifications->resolve($this->reminderKey($recurring, $day));
        $this->entityManager->flush();

        return $transaction;
    }

    private function linkOccurrence(RecurringTransaction $recurring, \DateTimeImmutable $day, Transaction $transaction): void
    {
        $transaction->setRecurring($recurring);
        $recurring->handleThrough($day);
        $this->notifications->resolve($this->reminderKey($recurring, $day));
    }

    /**
     * @param list<string> $used ids already taken by an earlier occurrence
     */
    private function closestMatch(RecurringTransaction $recurring, \DateTimeImmutable $day, array $used): ?Transaction
    {
        $candidates = array_filter(
            $this->transactionRepository->findRecurringCandidates(
                $recurring->getUser(),
                $recurring->getSourceAccount(),
                $recurring->getDestinationAccount(),
                $day->modify('-'.self::MATCH_DAYS.' days'),
                $day->modify('+'.self::MATCH_DAYS.' days'),
            ),
            static fn (Transaction $transaction) => !\in_array($transaction->getId()->toRfc4122(), $used, true),
        );
        usort($candidates, static fn (Transaction $a, Transaction $b) => self::distance($a, $day) <=> self::distance($b, $day));

        return $candidates[0] ?? null;
    }

    private static function distance(Transaction $transaction, \DateTimeImmutable $day): int
    {
        return abs((int) (new \DateTimeImmutable($transaction->getOccurredAt()->format('Y-m-d')))->diff($day)->format('%r%a'));
    }

    /** The reminder of one occurrence, or (no day) the start of all of a recurrence's. */
    private function reminderKey(RecurringTransaction $recurring, ?\DateTimeImmutable $day = null): string
    {
        return 'recurring:'.$recurring->getId()->toRfc4122().':'.($day?->format('Y-m-d') ?? '');
    }

    private function firstPending(RecurringTransaction $recurring): \DateTimeImmutable
    {
        $days = $recurring->getMode() === RecurrenceMode::Confirm && !$recurring->isPaused()
            ? $this->pendingDays($recurring, $this->today($recurring->getTimezone()))
            : [];
        if ($days === []) {
            throw new ApiException(ErrorCode::NothingToConfirm, [], Response::HTTP_CONFLICT);
        }

        return $days[0];
    }

    /** @return list<\DateTimeImmutable> */
    private function pendingDays(RecurringTransaction $recurring, \DateTimeImmutable $today): array
    {
        if ($recurring->getMode() !== RecurrenceMode::Confirm || $recurring->isPaused()) {
            return [];
        }

        return $recurring->rule()->between($recurring->getHandledThrough()->modify('+1 day'), $today);
    }

    /** Over the next twelve months, divided by twelve: a yearly subscription counts a twelfth, one ending soon less. */
    private function monthlyAmount(RecurringTransaction $recurring, \DateTimeImmutable $today): string
    {
        if ($recurring->isPaused()) {
            return '0.0000';
        }
        $total = '0';
        foreach ($recurring->rule()->between($today, $today->modify('+1 year -1 day')) as $day) {
            $total = bcadd($total, $recurring->amountOn($day), 4);
        }

        return bcdiv($total, '12', 4);
    }

    private function ownedTransaction(User $user, string $id): Transaction
    {
        $transaction = $this->transactionRepository->findOneByUserAndId($user, $id);
        if ($transaction === null) {
            throw ApiException::notFound(ErrorCode::TransactionNotFound);
        }

        return $transaction;
    }

    private function today(string $timezone): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->clock->now()->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d'));
    }
}
