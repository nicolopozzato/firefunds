<?php

namespace App\Transaction\DTO;

use App\Entity\TransactionAuditLog;

final readonly class TransactionAuditLogResponse
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

    public static function fromEntity(TransactionAuditLog $log): self
    {
        return new self(
            id: (string) $log->getId(),
            action: $log->getAction(),
            changes: $log->getChanges(),
            createdAt: $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
        );
    }
}
