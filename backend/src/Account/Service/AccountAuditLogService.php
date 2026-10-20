<?php

namespace App\Account\Service;

use App\Entity\Account;
use App\Entity\AccountAdjustment;
use App\Entity\AccountAuditLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes an account's history (AccountAuditLog). An update compares two
 * snapshots: the account's fields one by one, and its other balance changes
 * (AccountAdjustment) by id, each added, changed or removed one becoming a
 * change of field `adjustment` (null on the side where it doesn't exist).
 */
class AccountAuditLogService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function logCreated(Account $account, User $user): void
    {
        $this->entityManager->persist(new AccountAuditLog($account, $user, 'created'));
    }

    public function logDeleted(Account $account, User $user): void
    {
        $this->entityManager->persist(new AccountAuditLog($account, $user, 'deleted'));
    }

    /**
     * What an update is compared on, taken before and after it. The
     * adjustments are passed in: those just added or removed aren't in the
     * database yet.
     *
     * @param AccountAdjustment[] $adjustments the account's adjustments at that point
     * @return array{fields: array<string, mixed>, adjustments: array<string, array{date: string, amount: string, description: ?string}>}
     */
    public function snapshot(Account $account, array $adjustments): array
    {
        $byId = [];
        foreach ($adjustments as $adjustment) {
            $byId[$adjustment->getId()->toRfc4122()] = [
                'date' => $adjustment->getDate()->format('Y-m-d'),
                'amount' => bcadd($adjustment->getAmount(), '0', 4),
                'description' => $adjustment->getDescription(),
            ];
        }

        return [
            'fields' => [
                'name' => $account->getName(),
                'iban' => $account->getIban(),
                'bic' => $account->getBic(),
                'accountNumber' => $account->getAccountNumber(),
                'notes' => $account->getNotes(),
                'isActive' => $account->isActive(),
                'currency' => $account->getCurrency()?->getCode(),
                'openingBalance' => $account->getOpeningBalance() !== null ? bcadd($account->getOpeningBalance(), '0', 4) : null,
                'openingBalanceDate' => $account->getOpeningBalanceDate()?->format('Y-m-d'),
            ],
            'adjustments' => $byId,
        ];
    }

    /**
     * @param array{fields: array<string, mixed>, adjustments: array<string, array{date: string, amount: string, description: ?string}>} $before
     * @param AccountAdjustment[] $adjustments the account's adjustments after the update
     */
    public function logUpdated(Account $account, User $user, array $before, array $adjustments): void
    {
        $after = $this->snapshot($account, $adjustments);
        $changes = [];

        foreach ($after['fields'] as $field => $newValue) {
            $oldValue = $before['fields'][$field] ?? null;
            if ($oldValue !== $newValue) {
                $changes[] = ['field' => $field, 'oldValue' => $oldValue, 'newValue' => $newValue];
            }
        }

        foreach ($before['adjustments'] + $after['adjustments'] as $id => $_) {
            $oldValue = $before['adjustments'][$id] ?? null;
            $newValue = $after['adjustments'][$id] ?? null;
            if ($oldValue !== $newValue) {
                $changes[] = ['field' => 'adjustment', 'oldValue' => $oldValue, 'newValue' => $newValue];
            }
        }

        if ($changes === []) {
            return;
        }

        $this->entityManager->persist(new AccountAuditLog($account, $user, 'updated', $changes));
    }
}
