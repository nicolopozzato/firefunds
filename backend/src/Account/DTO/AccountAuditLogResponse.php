<?php

namespace App\Account\DTO;

use App\Entity\AccountAuditLog;

final readonly class AccountAuditLogResponse
{
    /**
     * @param array<int, array{field: string, oldValue: mixed, newValue: mixed}>|null $changes
     *        `adjustment` changes carry `{date, amount, description}`, null on the side where it doesn't exist
     */
    public function __construct(
        public string $id,
        public string $action,
        public ?array $changes,
        public string $createdAt,
    ) {
    }

    public static function fromEntity(AccountAuditLog $log): self
    {
        return new self(
            id: (string) $log->getId(),
            action: $log->getAction(),
            changes: $log->getChanges(),
            createdAt: $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
        );
    }
}
