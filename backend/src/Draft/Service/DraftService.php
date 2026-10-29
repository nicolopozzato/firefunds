<?php

namespace App\Draft\Service;

use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Core\Period\TransactionMoment;
use App\Draft\DTO\AcceptDraftRequest;
use App\Draft\DTO\CreateDraftRequest;
use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Draft;
use App\Entity\Transaction;
use App\Entity\User;
use App\Notification\Service\NotificationCenter;
use App\Recurring\Service\RecurringTransactionService;
use App\Repository\AccountRepository;
use App\Repository\DraftRepository;
use App\Repository\TransactionRepository;
use App\Transaction\DTO\CreateTransactionRequest;
use App\Transaction\Service\TransactionPayloads;
use App\Transaction\Service\TransactionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * What comes from outside, before it is a transaction. On arrival a draft is
 * checked against what is there: an identical transaction (handled as the
 * sender asked, `ifExists`), else a recurring transaction expected those
 * days (recorded now as that occurrence); anything else waits for the user,
 * who is told at once (notification `draft_new`, pushed right away).
 */
class DraftService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountRepository $accounts,
        private readonly TransactionRepository $transactions,
        private readonly TransactionService $transactionService,
        private readonly RecurringTransactionService $recurring,
        private readonly NotificationCenter $notifications,
        private readonly TransactionPayloads $parts,
        private readonly DraftRepository $drafts,
    ) {
    }

    public function create(User $user, CreateDraftRequest $request, string $source, ?string $sourceName): Draft
    {
        $timezone = $request->timezone ?? $user->getTimezone();
        $occurredAt = TransactionMoment::parse($request->occurredAt, $timezone);
        $draft = new Draft(
            user: $user,
            source: $request->source ?? $source,
            sourceName: $request->sourceName ?? $sourceName,
            amount: bcadd($request->amount, '0', 4),
            occurredAt: new \DateTimeImmutable($occurredAt->format('Y-m-d H:i:s')),
            timezone: $occurredAt->getTimezone()->getName(),
            description: $request->description !== null ? trim($request->description) : null,
            direction: $request->direction,
            sourceAccount: $this->ownedAccount($user, $request->sourceAccountId),
            destinationAccount: $this->ownedAccount($user, $request->destinationAccountId),
            notes: $request->notes,
            rawText: $request->rawText,
        );
        $this->entityManager->persist($draft);

        $existing = $request->ifExists === 'create'
            ? null
            : $this->transactions->findIdentical($user, $draft->getAssetAccount(), $draft->getAmount(), new \DateTimeImmutable($draft->getOccurredAt()->format('Y-m-d')));
        if ($existing !== null && $request->ifExists !== 'review') {
            if ($request->ifExists === 'annotate') {
                $this->annotate($existing, $draft->getDescription(), $draft->getNotes());
            }
            $draft->close(Draft::STATUS_MATCHED, [$existing->getId()->toRfc4122()]);
        } elseif ($existing === null && ($match = $this->recurring->matchDraft($draft)) !== null) {
            [$recurring, $day] = $match;
            $transaction = $this->recurring->recordFromDraft($recurring, $day, $draft);
            $draft->close(Draft::STATUS_MATCHED, [$transaction->getId()->toRfc4122()]);
            $this->notifications->raise($user, 'draft_recorded', 'draft:'.$draft->getId()->toRfc4122(), [
                'transactionId' => $transaction->getId()->toRfc4122(),
                'description' => $recurring->getDescription(),
                'amount' => $draft->getAmount(),
                'currency' => $transaction->getCurrency(),
            ]);
        } else {
            $draft->setPossibleDuplicate($existing);
            $this->notifications->raise($user, 'draft_new', 'draft:'.$draft->getId()->toRfc4122(), [
                'draftId' => $draft->getId()->toRfc4122(),
                'description' => $draft->getDescription() ?? $draft->getSourceName(),
                'amount' => $draft->getAmount(),
                'currency' => $this->currency($draft),
                'duplicate' => $existing !== null ? 1 : 0,
            ]);
        }
        $this->entityManager->flush();

        return $draft;
    }

    /**
     * A transaction sent with `ifExists` when an identical one is there:
     * that one (`skip`, or `annotate` with this one's text in its notes), or
     * a draft asking the user (`review`). Null: nothing like it, create it.
     */
    public function incomingTransaction(User $user, CreateTransactionRequest $request): Transaction|Draft|null
    {
        $account = $this->ownedAccount($user, $request->sourceAccountId) ?? $this->ownedAccount($user, $request->destinationAccountId);
        $day = TransactionMoment::parse($request->occurredAt, $request->timezone ?? $user->getTimezone());
        $existing = $this->transactions->findIdentical($user, $account, $request->amount, new \DateTimeImmutable($day->format('Y-m-d')));
        if ($existing === null) {
            return null;
        }
        if ($request->ifExists === 'annotate') {
            $this->annotate($existing, $request->description, $request->notes);
            $this->entityManager->flush();
        }
        if ($request->ifExists !== 'review') {
            return $existing;
        }

        return $this->create($user, new CreateDraftRequest(
            amount: $request->amount,
            occurredAt: $request->occurredAt,
            description: $request->description,
            timezone: $request->timezone,
            sourceAccountId: $request->sourceAccountId,
            destinationAccountId: $request->destinationAccountId,
            notes: $request->notes,
        ), 'api', null);
    }

    /**
     * The draft becomes these transactions (several when split), checked as
     * any transaction is; all or none.
     *
     * @return list<Transaction>
     */
    public function accept(Draft $draft, AcceptDraftRequest $request): array
    {
        $this->assertPending($draft);
        $parts = $this->parts->read($request->parts);

        return $this->entityManager->wrapInTransaction(function () use ($draft, $parts): array {
            $created = [];
            foreach ($parts as $part) {
                $created[] = $this->transactionService->create($draft->getUser(), $part);
            }
            $draft->close(Draft::STATUS_ACCEPTED, array_map(static fn (Transaction $t) => $t->getId()->toRfc4122(), $created));
            $this->notifications->resolve('draft:'.$draft->getId()->toRfc4122());
            foreach ($created as $transaction) {
                $this->closeOtherLeg($draft, $transaction);
            }
            $this->entityManager->flush();

            return $created;
        });
    }

    /** "It is this one, already recorded": the draft closes on it. */
    public function link(Draft $draft, string $transactionId): void
    {
        $this->assertPending($draft);
        $transaction = $this->transactions->findOneByUserAndId($draft->getUser(), $transactionId)
            ?? throw ApiException::notFound(ErrorCode::TransactionNotFound);
        $draft->close(Draft::STATUS_MATCHED, [$transaction->getId()->toRfc4122()]);
        $this->notifications->resolve('draft:'.$draft->getId()->toRfc4122());
        $this->entityManager->flush();
    }

    public function discard(Draft $draft): void
    {
        $this->assertPending($draft);
        $draft->close(Draft::STATUS_DISCARDED, []);
        $this->notifications->resolve('draft:'.$draft->getId()->toRfc4122());
        $this->entityManager->flush();
    }

    /**
     * A transfer between two of the user's accounts is often told twice (the
     * bank it leaves, the one it reaches): the other leg's draft, still
     * pending, closes on the transfer just recorded.
     */
    private function closeOtherLeg(Draft $recorded, Transaction $transfer): void
    {
        $from = $transfer->getSourceAccount();
        $to = $transfer->getDestinationAccount();
        if ($from?->getType() !== AccountType::Asset || $to?->getType() !== AccountType::Asset) {
            return;
        }
        foreach ($this->drafts->findPending($recorded->getUser()) as $other) {
            if ($other->getId()->equals($recorded->getId())) {
                continue;
            }
            $direction = $other->effectiveDirection() ?? $other->getDirection();
            $account = $other->getAssetAccount();
            $leg = match (true) {
                $direction === 'out' && $account?->getId()->equals($from->getId()) => $transfer->getAmount(),
                $direction === 'in' && $account?->getId()->equals($to->getId()) => $transfer->getDestinationAmount() ?? $transfer->getAmount(),
                default => null,
            };
            if ($leg !== null && bccomp($other->getAmount(), $leg, 4) === 0
                && abs($other->getOccurredAt()->getTimestamp() - $transfer->getOccurredAt()->getTimestamp()) <= 86_400) {
                $other->close(Draft::STATUS_MATCHED, [$transfer->getId()->toRfc4122()]);
                $this->notifications->resolve('draft:'.$other->getId()->toRfc4122());

                return;
            }
        }
    }

    /** The currency of its account, else the user's. */
    public function currency(Draft $draft): string
    {
        return $draft->getAssetAccount()?->getCurrency()?->getCode() ?? $draft->getUser()->getCurrency();
    }

    /** The incoming one's description and notes added to the notes of the one already there, once. */
    private function annotate(Transaction $transaction, ?string $description, ?string $notes): void
    {
        $text = trim(implode(' · ', array_filter([$description, $notes])));
        if ($text !== '' && !str_contains((string) $transaction->getNotes(), $text)) {
            $transaction->setNotes(trim(($transaction->getNotes() ?? '')."\n".$text));
        }
    }

    private function assertPending(Draft $draft): void
    {
        if (!$draft->isPending()) {
            throw new ApiException(ErrorCode::DraftClosed, [], Response::HTTP_CONFLICT);
        }
    }

    private function ownedAccount(User $user, ?string $id): ?Account
    {
        if ($id === null) {
            return null;
        }

        return $this->accounts->findOneByUserAndId($user, $id) ?? throw ApiException::notFound(ErrorCode::AccountNotFound);
    }
}
