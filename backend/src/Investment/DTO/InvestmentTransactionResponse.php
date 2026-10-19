<?php

namespace App\Investment\DTO;

use App\Entity\InvestmentTransaction;
use App\Transaction\DTO\AccountRefResponse;

final readonly class InvestmentTransactionResponse
{
    public function __construct(
        public string $id,
        public ?string $description,
        public AccountRefResponse $account,
        public string $instrumentId,
        public string $instrumentName,
        public ?string $instrumentTicker,
        public string $type,
        public string $quantity,
        public string $unitPrice,
        /** What the units cost/fetched, fees and taxes excluded (see InvestmentTransaction::$amount). */
        public string $amount,
        public string $fees,
        public string $taxes,
        public string $totalAmount,
        /** The broker account's currency code: unit price, fees, taxes and total are all expressed in it for now. */
        public string $currency,
        /** The clock time where it happened, without offset: show it as it is. */
        public string $occurredAt,
        /** Where it happened (App\Entity\OccursLocally). */
        public string $timezone,
        public ?string $notes,
        public string $createdAt,
        public string $updatedAt,
        /**
         * True when the instrument's daily closing-price history still has a
         * gap through yesterday after a best-effort backfill attempt right
         * after this save — charts/reports involving it may be incomplete
         * until prices are imported manually from the instrument's page.
         */
        public bool $pricesIncomplete = false,
    ) {
    }

    public static function fromEntity(InvestmentTransaction $transaction, bool $pricesIncomplete = false): self
    {
        $instrument = $transaction->getInstrument();

        return new self(
            id: (string) $transaction->getId(),
            description: $transaction->getDescription(),
            account: AccountRefResponse::fromEntity($transaction->getAccount()),
            instrumentId: (string) $instrument->getId(),
            instrumentName: $instrument->getName(),
            instrumentTicker: $instrument->getTicker(),
            type: $transaction->getType()->value,
            quantity: $transaction->getQuantity(),
            unitPrice: $transaction->getUnitPrice(),
            amount: $transaction->getAmount(),
            fees: $transaction->getFees(),
            taxes: $transaction->getTaxes(),
            totalAmount: $transaction->getTotalAmount(),
            currency: $transaction->getAccount()->getCurrency()?->getCode() ?? '',
            occurredAt: $transaction->getOccurredAt()->format('Y-m-d\TH:i:s'),
            timezone: $transaction->getTimezone(),
            notes: $transaction->getNotes(),
            createdAt: $transaction->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updatedAt: $transaction->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            pricesIncomplete: $pricesIncomplete,
        );
    }
}
