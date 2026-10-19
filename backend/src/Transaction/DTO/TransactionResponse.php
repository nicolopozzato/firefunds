<?php

namespace App\Transaction\DTO;

use App\Entity\InvestmentTransaction;
use App\Entity\InvestmentTransactionType;
use App\Entity\Transaction;

final readonly class TransactionResponse
{
    public function __construct(
        public string $id,
        public ?string $description,
        /** Null: money coming from outside the tracked accounts ("external"). */
        public ?AccountRefResponse $sourceAccount,
        /** Null: money leaving the tracked accounts ("external"). */
        public ?AccountRefResponse $destinationAccount,
        public string $occurredAt,
        public string $amount,
        public string $currency,
        public ?string $foreignAmount,
        public ?string $foreignCurrency,
        public ?string $categoryId,
        public ?string $categoryName,
        /** @var array{id: string, name: string}[] */
        public array $tags,
        public ?string $notes,
        public string $createdAt,
        public string $updatedAt,
        /**
         * Discriminates a plain money transaction from an investment BUY/SELL,
         * so a merged "all transactions" list can render each row correctly
         * without the two entities being modeled as one.
         */
        public string $kind = 'money',
        /** Investment BUY/SELL only: units traded, price per unit, fees and taxes, all null for a money transaction. */
        public ?string $quantity = null,
        public ?string $unitPrice = null,
        public ?string $fees = null,
        public ?string $taxes = null,
    ) {
    }

    public static function fromEntity(Transaction $transaction): self
    {
        $category = $transaction->getCategory();

        return new self(
            id: (string) $transaction->getId(),
            description: $transaction->getDescription(),
            sourceAccount: AccountRefResponse::fromNullable($transaction->getSourceAccount()),
            destinationAccount: AccountRefResponse::fromNullable($transaction->getDestinationAccount()),
            occurredAt: $transaction->getOccurredAt()->format(\DateTimeInterface::ATOM),
            amount: $transaction->getAmount(),
            currency: $transaction->getCurrency(),
            foreignAmount: $transaction->getForeignAmount(),
            foreignCurrency: $transaction->getForeignCurrency(),
            categoryId: $category !== null ? (string) $category->getId() : null,
            categoryName: $category?->getName(),
            tags: array_map(
                static fn ($tag) => ['id' => (string) $tag->getId(), 'name' => $tag->getName()],
                $transaction->getTags()->toArray()
            ),
            notes: $transaction->getNotes(),
            createdAt: $transaction->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updatedAt: $transaction->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    /**
     * Represents a BUY/SELL investment transaction using the same shape as a
     * money transaction, so both can appear together in the "all transactions"
     * list: the instrument stands in for the destination account, the amount is
     * the total cash effect (the trade's amount, fees and taxes included), and
     * there's never a category. Quantity, unit price, fees and taxes are passed
     * through too, so a broker account's list can show the trade's breakdown.
     */
    public static function fromInvestmentEntity(InvestmentTransaction $transaction): self
    {
        $instrument = $transaction->getInstrument();
        $isBuy = $transaction->getType() === InvestmentTransactionType::Buy;

        return new self(
            id: (string) $transaction->getId(),
            description: $transaction->getDescription() ?? $instrument->getName(),
            sourceAccount: AccountRefResponse::fromEntity($transaction->getAccount()),
            // Short: the ticker (the full name is on the instrument's page), plus its type.
            destinationAccount: new AccountRefResponse(
                id: (string) $instrument->getId(),
                name: $instrument->getTicker() ?? $instrument->getName(),
                type: 'instrument',
                instrumentType: $instrument->getType()->value,
            ),
            occurredAt: $transaction->getOccurredAt()->format(\DateTimeInterface::ATOM),
            amount: $transaction->getTotalAmount(),
            currency: $transaction->getAccount()->getCurrency()?->getCode() ?? '',
            foreignAmount: null,
            foreignCurrency: null,
            categoryId: null,
            categoryName: null,
            tags: [],
            notes: $transaction->getNotes(),
            createdAt: $transaction->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updatedAt: $transaction->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            kind: $isBuy ? 'investmentBuy' : 'investmentSell',
            quantity: $transaction->getQuantity(),
            unitPrice: $transaction->getUnitPrice(),
            fees: $transaction->getFees(),
            taxes: $transaction->getTaxes(),
        );
    }
}
