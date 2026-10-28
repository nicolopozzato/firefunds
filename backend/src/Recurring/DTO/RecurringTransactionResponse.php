<?php

namespace App\Recurring\DTO;

use App\Entity\AccountType;
use App\Entity\RecurringTransaction;
use App\Transaction\DTO\AccountRefResponse;

final readonly class RecurringTransactionResponse
{
    /**
     * @param array<array{id: string, name: string}> $tags
     * @param list<int>|null                          $months
     * @param list<array{from: string, amount: string}> $amountChanges
     */
    public function __construct(
        public string $id,
        public string $description,
        /** withdrawal, deposit or transfer */
        public string $kind,
        public ?AccountRefResponse $sourceAccount,
        public ?AccountRefResponse $destinationAccount,
        public string $amount,
        /** Planned changes of the amount, oldest first: from that day on, that amount. */
        public array $amountChanges,
        /** The amount of the next occurrence (a planned change may have come by then). */
        public string $nextAmount,
        public string $currency,
        public ?string $destinationAmount,
        public ?string $categoryId,
        public ?string $categoryName,
        public array $tags,
        public ?string $notes,
        public string $startDate,
        public string $unit,
        public int $interval,
        public string $monthDay,
        public string $weekendShift,
        public ?array $months,
        public ?string $endDate,
        public ?int $count,
        public string $time,
        public string $timezone,
        public string $mode,
        public int $reminderDays,
        public bool $paused,
        /** The next occurrence not dealt with yet (Y-m-d); null once it has ended. */
        public ?string $nextOccurrence,
        /** Waiting for the user (mode confirm): occurrences due by today. */
        public int $pending,
        /** What it moves over the next twelve months, per month. */
        public string $monthlyAmount,
    ) {
    }

    public static function fromEntity(RecurringTransaction $recurring, int $pending, string $monthlyAmount): self
    {
        $source = $recurring->getSourceAccount();
        $destination = $recurring->getDestinationAccount();
        $category = $recurring->getCategory();

        return new self(
            id: (string) $recurring->getId(),
            description: $recurring->getDescription(),
            kind: self::kind($source?->getType(), $destination?->getType()),
            sourceAccount: AccountRefResponse::fromNullable($source),
            destinationAccount: AccountRefResponse::fromNullable($destination),
            amount: $recurring->getAmount(),
            amountChanges: $recurring->getAmountChanges(),
            nextAmount: $recurring->amountOn($recurring->nextOccurrence() ?? new \DateTimeImmutable()),
            currency: $recurring->getAssetAccount()->getCurrency()?->getCode() ?? '',
            destinationAmount: $recurring->getDestinationAmount(),
            categoryId: $category !== null ? (string) $category->getId() : null,
            categoryName: $category?->getName(),
            tags: array_values(array_map(
                static fn ($tag) => ['id' => (string) $tag->getId(), 'name' => $tag->getName()],
                $recurring->getTags()->toArray(),
            )),
            notes: $recurring->getNotes(),
            startDate: $recurring->getStartDate()->format('Y-m-d'),
            unit: $recurring->getUnit()->value,
            interval: $recurring->getInterval(),
            monthDay: $recurring->getMonthDay()->value,
            weekendShift: $recurring->getWeekendShift()->value,
            months: $recurring->getMonths(),
            endDate: $recurring->getEndDate()?->format('Y-m-d'),
            count: $recurring->getCount(),
            time: $recurring->getTime(),
            timezone: $recurring->getTimezone(),
            mode: $recurring->getMode()->value,
            reminderDays: $recurring->getReminderDays(),
            paused: $recurring->isPaused(),
            nextOccurrence: $recurring->nextOccurrence()?->format('Y-m-d'),
            pending: $pending,
            monthlyAmount: $monthlyAmount,
        );
    }

    private static function kind(?AccountType $source, ?AccountType $destination): string
    {
        if ($source === AccountType::Asset && $destination === AccountType::Asset) {
            return 'transfer';
        }

        return $source === AccountType::Asset ? 'withdrawal' : 'deposit';
    }
}
