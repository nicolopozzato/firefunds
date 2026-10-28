<?php

namespace App\Transaction\Service;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Tag;
use App\Entity\Transaction;
use App\Entity\TransactionAuditLog;
use App\Entity\User;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;

class TransactionAuditLogService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function logCreated(Transaction $transaction, User $user): void
    {
        $this->entityManager->persist(new TransactionAuditLog($transaction, $user, 'created'));
    }

    public function logDeleted(Transaction $transaction, User $user): void
    {
        $this->entityManager->persist(new TransactionAuditLog($transaction, $user, 'deleted'));
    }

    public function logRestored(Transaction $transaction, User $user): void
    {
        $this->entityManager->persist(new TransactionAuditLog($transaction, $user, 'restored'));
    }

    /**
     * Captures a snapshot of the fields that matter for audit purposes, to be compared
     * against the transaction's state after the update is applied.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Transaction $transaction): array
    {
        return [
            'description' => $transaction->getDescription(),
            'sourceAccount' => $this->accountLabel($transaction->getSourceAccount()),
            'destinationAccount' => $this->accountLabel($transaction->getDestinationAccount()),
            'occurredAt' => $this->normalizeOccurredAt($transaction->getOccurredAt()),
            'amount' => $this->normalizeAmount($transaction->getAmount()),
            'currency' => $transaction->getCurrency(),
            'destinationAmount' => $transaction->getDestinationAmount() !== null ? $this->normalizeAmount($transaction->getDestinationAmount()) : null,
            'foreignAmount' => $transaction->getForeignAmount() !== null ? $this->normalizeAmount($transaction->getForeignAmount()) : null,
            'foreignCurrency' => $transaction->getForeignCurrency(),
            'category' => $this->categoryLabel($transaction->getCategory()),
            'tags' => $this->tagLabels($transaction->getTags()),
            'notes' => $transaction->getNotes(),
        ];
    }

    /**
     * @param array<string, mixed> $before
     */
    public function logUpdated(Transaction $transaction, User $user, array $before): void
    {
        $after = $this->snapshot($transaction);
        $changes = [];

        foreach ($after as $field => $newValue) {
            $oldValue = $before[$field] ?? null;

            if ($oldValue !== $newValue) {
                $changes[] = [
                    'field' => $field,
                    'oldValue' => $oldValue,
                    'newValue' => $newValue,
                ];
            }
        }

        if ($changes === []) {
            return;
        }

        $this->entityManager->persist(new TransactionAuditLog($transaction, $user, 'updated', $changes));
    }

    /**
     * Normalizes a decimal(19,4) amount string so equal values compare equal
     * regardless of trailing-zero formatting (e.g. "9" vs "9.0000").
     */
    private function normalizeAmount(string $amount): string
    {
        return number_format((float) $amount, 4, '.', '');
    }

    /**
     * The transaction form only lets users pick a date and time down to the minute,
     * so seconds carried over from elsewhere (e.g. the original creation timestamp)
     * must not register as a change.
     */
    private function normalizeOccurredAt(\DateTimeImmutable $occurredAt): string
    {
        return $occurredAt->setTime((int) $occurredAt->format('H'), (int) $occurredAt->format('i'))
            ->format(\DateTimeInterface::ATOM);
    }

    /** Null for the "external" side of a transaction. */
    private function accountLabel(?Account $account): ?string
    {
        return $account?->getName();
    }

    private function categoryLabel(?Category $category): ?string
    {
        return $category?->getName();
    }

    /**
     * @param Collection<int, Tag> $tags
     *
     * @return string[]
     */
    private function tagLabels(Collection $tags): array
    {
        $names = array_map(static fn (Tag $tag) => $tag->getName(), $tags->toArray());
        sort($names);

        return $names;
    }
}
