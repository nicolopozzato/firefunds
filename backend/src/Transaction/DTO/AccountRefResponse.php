<?php

namespace App\Transaction\DTO;

use App\Entity\Account;

final readonly class AccountRefResponse
{
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        /** Only for an instrument standing in as a side (type "instrument"): etf, stock… */
        public ?string $instrumentType = null,
    ) {
    }

    public static function fromEntity(Account $account): self
    {
        return new self(
            id: (string) $account->getId(),
            name: $account->getName(),
            type: $account->getType()->value,
        );
    }

    /** Null stays null: the "external" side of a transaction. */
    public static function fromNullable(?Account $account): ?self
    {
        return $account !== null ? self::fromEntity($account) : null;
    }
}
