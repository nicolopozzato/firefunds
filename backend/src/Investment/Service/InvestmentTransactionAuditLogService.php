<?php

namespace App\Investment\Service;

use App\Entity\Account;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentTransaction;
use App\Entity\InvestmentTransactionAuditLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class InvestmentTransactionAuditLogService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function logCreated(InvestmentTransaction $transaction, User $user): void
    {
        $this->entityManager->persist(new InvestmentTransactionAuditLog($transaction, $user, 'created'));
    }

    public function logDeleted(InvestmentTransaction $transaction, User $user): void
    {
        $this->entityManager->persist(new InvestmentTransactionAuditLog($transaction, $user, 'deleted'));
    }

    /**
     * Captures a snapshot of the fields that matter for audit purposes, to be compared
     * against the investment transaction's state after the update is applied.
     *
     * @return array<string, mixed>
     */
    public function snapshot(InvestmentTransaction $transaction): array
    {
        return [
            'description' => $transaction->getDescription(),
            'account' => $this->accountLabel($transaction->getAccount()),
            'instrument' => $this->instrumentLabel($transaction->getInstrument()),
            'type' => $transaction->getType()->value,
            'quantity' => $this->normalizeDecimal($transaction->getQuantity()),
            'unitPrice' => $this->normalizeDecimal($transaction->getUnitPrice()),
            'amount' => $this->normalizeDecimal($transaction->getAmount()),
            'fees' => $this->normalizeDecimal($transaction->getFees()),
            'taxes' => $this->normalizeDecimal($transaction->getTaxes()),
            'occurredAt' => $this->normalizeOccurredAt($transaction->getOccurredAt()),
            'notes' => $transaction->getNotes(),
        ];
    }

    /**
     * @param array<string, mixed> $before
     */
    public function logUpdated(InvestmentTransaction $transaction, User $user, array $before): void
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

        $this->entityManager->persist(new InvestmentTransactionAuditLog($transaction, $user, 'updated', $changes));
    }

    /**
     * Normalizes a decimal string so equal values compare equal regardless of
     * trailing-zero formatting (e.g. "9" vs "9.0000").
     */
    private function normalizeDecimal(string $value): string
    {
        return number_format((float) $value, 6, '.', '');
    }

    /**
     * The investment transaction form only lets users pick a date and time down
     * to the minute, so seconds carried over from elsewhere must not register
     * as a change.
     */
    private function normalizeOccurredAt(\DateTimeImmutable $occurredAt): string
    {
        return $occurredAt->setTime((int) $occurredAt->format('H'), (int) $occurredAt->format('i'))
            ->format(\DateTimeInterface::ATOM);
    }

    private function accountLabel(Account $account): string
    {
        return $account->getName();
    }

    private function instrumentLabel(InvestmentInstrument $instrument): string
    {
        return $instrument->getName();
    }
}
