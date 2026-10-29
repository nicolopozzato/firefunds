<?php

namespace App\Draft\DTO;

use App\Entity\Draft;
use App\Transaction\DTO\AccountRefResponse;
use App\Transaction\DTO\TransactionResponse;

final readonly class DraftResponse
{
    /**
     * @param list<string> $transactionIds
     * @param array<string, mixed>|null $suggestion what it most likely is (DraftSuggestions), pending drafts only
     */
    public function __construct(
        public string $id,
        public string $status,
        public string $source,
        public ?string $sourceName,
        public ?string $description,
        public string $amount,
        public string $currency,
        public ?string $direction,
        public string $occurredAt,
        public string $timezone,
        public ?AccountRefResponse $sourceAccount,
        public ?AccountRefResponse $destinationAccount,
        public ?string $notes,
        public ?string $rawText,
        /** An identical transaction already recorded: is it this one? */
        public ?TransactionResponse $possibleDuplicate,
        public array $transactionIds,
        public string $createdAt,
        public ?array $suggestion = null,
    ) {
    }

    /** @param array<string, mixed>|null $suggestion */
    public static function fromEntity(Draft $draft, string $currency, ?array $suggestion = null): self
    {
        return new self(
            id: (string) $draft->getId(),
            status: $draft->getStatus(),
            source: $draft->getSource(),
            sourceName: $draft->getSourceName(),
            description: $draft->getDescription(),
            amount: $draft->getAmount(),
            currency: $currency,
            direction: $draft->effectiveDirection(),
            occurredAt: $draft->getOccurredAt()->format('Y-m-d\TH:i:s'),
            timezone: $draft->getTimezone(),
            sourceAccount: AccountRefResponse::fromNullable($draft->getSourceAccount()),
            destinationAccount: AccountRefResponse::fromNullable($draft->getDestinationAccount()),
            notes: $draft->getNotes(),
            rawText: $draft->getRawText(),
            possibleDuplicate: $draft->getPossibleDuplicate() !== null ? TransactionResponse::fromEntity($draft->getPossibleDuplicate()) : null,
            transactionIds: $draft->getTransactionIds(),
            createdAt: $draft->getCreatedAt()->format(\DateTimeInterface::ATOM),
            suggestion: $suggestion,
        );
    }
}
