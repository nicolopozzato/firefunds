<?php

namespace App\Account\DTO;

use App\Entity\Account;

final readonly class AccountResponse
{
    public function __construct(
        public string $id,
        public string $type,
        public string $name,
        public ?string $iban,
        public ?string $bic,
        public ?string $accountNumber,
        public ?string $notes,
        public bool $isActive,
        public bool $showOnDashboard,
        public ?string $assetKind,
        public ?string $currencyCode,
        public ?string $currencySymbol,
        public ?string $openingBalance,
        public ?string $openingBalanceDate,
        public string $currentBalance,
        public ?string $lastActivityAt,
        public string $createdAt,
        public string $updatedAt,
        /**
         * Current market value of everything held on this account, only ever
         * set for an investment account. `currentBalance` above stays what it
         * always was: the account's free cash, not counting invested value.
         */
        public ?string $investedValue = null,
        /** investedValue minus the positions' cost basis (average-cost method); positive = unrealized gain. */
        public ?string $gainAmount = null,
        /** gainAmount as a percentage of the cost basis. */
        public ?string $gainPercent = null,
        /**
         * Capital gains tax owed if every position were sold today, position by
         * position (a loss on one doesn't offset another's gain); an estimate.
         */
        public ?string $estimatedTax = null,
    ) {
    }

    public static function fromEntity(
        Account $account,
        string $currentBalance,
        ?string $lastActivityAt,
        ?string $investedValue = null,
        ?string $gainAmount = null,
        ?string $gainPercent = null,
        ?string $estimatedTax = null,
    ): self {
        $currency = $account->getCurrency();

        return new self(
            id: (string) $account->getId(),
            type: $account->getType()->value,
            name: $account->getName(),
            iban: $account->getIban(),
            bic: $account->getBic(),
            accountNumber: $account->getAccountNumber(),
            notes: $account->getNotes(),
            isActive: $account->isActive(),
            showOnDashboard: $account->isShownOnDashboard(),
            assetKind: $account->getAssetKind()?->value,
            currencyCode: $currency?->getCode(),
            currencySymbol: $currency?->getSymbol(),
            openingBalance: $account->getOpeningBalance(),
            openingBalanceDate: $account->getOpeningBalanceDate()?->format('Y-m-d'),
            currentBalance: $currentBalance,
            lastActivityAt: $lastActivityAt !== null
                ? (new \DateTimeImmutable($lastActivityAt))->format(\DateTimeInterface::ATOM)
                : null,
            createdAt: $account->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updatedAt: $account->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            investedValue: $investedValue,
            gainAmount: $gainAmount,
            gainPercent: $gainPercent,
            estimatedTax: $estimatedTax,
        );
    }
}
