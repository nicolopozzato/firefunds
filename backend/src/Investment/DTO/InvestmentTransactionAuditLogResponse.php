<?php

namespace App\Investment\DTO;

use App\Entity\InvestmentTransactionAuditLog;

final readonly class InvestmentTransactionAuditLogResponse
{
    /**
     * @param array<int, array{field: string, oldValue: mixed, newValue: mixed}>|null $changes
     */
    public function __construct(
        public string $id,
        public string $action,
        public ?array $changes,
        public string $createdAt,
    ) {
    }

    public static function fromEntity(InvestmentTransactionAuditLog $log): self
    {
        return new self(
            id: (string) $log->getId(),
            action: $log->getAction(),
            changes: $log->getChanges(),
            createdAt: $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
        );
    }
}
