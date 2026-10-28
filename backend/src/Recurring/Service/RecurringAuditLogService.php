<?php

namespace App\Recurring\Service;

use App\Entity\RecurringTransaction;
use App\Entity\RecurringTransactionAuditLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes a recurring transaction's history (RecurringTransactionAuditLog):
 * an update compares two snapshots field by field, names rather than ids
 * (an account, the category, the tags), so the history reads the same after
 * a rename.
 */
class RecurringAuditLogService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function logCreated(RecurringTransaction $recurring, User $user): void
    {
        $this->entityManager->persist(new RecurringTransactionAuditLog($recurring, $user, 'created'));
    }

    public function logDeleted(RecurringTransaction $recurring, User $user): void
    {
        $this->entityManager->persist(new RecurringTransactionAuditLog($recurring, $user, 'deleted'));
    }

    /** @return array<string, mixed> */
    public function snapshot(RecurringTransaction $recurring): array
    {
        $tags = array_map(static fn ($tag) => $tag->getName(), $recurring->getTags()->toArray());
        sort($tags);

        return [
            'description' => $recurring->getDescription(),
            'sourceAccount' => $recurring->getSourceAccount()?->getName(),
            'destinationAccount' => $recurring->getDestinationAccount()?->getName(),
            'amount' => bcadd($recurring->getAmount(), '0', 4),
            'amountChanges' => $recurring->getAmountChanges(),
            'destinationAmount' => $recurring->getDestinationAmount() !== null ? bcadd($recurring->getDestinationAmount(), '0', 4) : null,
            'category' => $recurring->getCategory()?->getName(),
            'tags' => $tags,
            'notes' => $recurring->getNotes(),
            'startDate' => $recurring->getStartDate()->format('Y-m-d'),
            'unit' => $recurring->getUnit()->value,
            'interval' => $recurring->getInterval(),
            'monthDay' => $recurring->getMonthDay()->value,
            'weekendShift' => $recurring->getWeekendShift()->value,
            'months' => $recurring->getMonths(),
            'endDate' => $recurring->getEndDate()?->format('Y-m-d'),
            'count' => $recurring->getCount(),
            'time' => $recurring->getTime(),
            'mode' => $recurring->getMode()->value,
            'reminderDays' => $recurring->getReminderDays(),
            'paused' => $recurring->isPaused(),
        ];
    }

    /** @param array<string, mixed> $before */
    public function logUpdated(RecurringTransaction $recurring, User $user, array $before): void
    {
        $changes = [];
        foreach ($this->snapshot($recurring) as $field => $newValue) {
            $oldValue = $before[$field] ?? null;
            if ($oldValue !== $newValue) {
                $changes[] = ['field' => $field, 'oldValue' => $oldValue, 'newValue' => $newValue];
            }
        }

        if ($changes !== []) {
            $this->entityManager->persist(new RecurringTransactionAuditLog($recurring, $user, 'updated', $changes));
        }
    }
}
