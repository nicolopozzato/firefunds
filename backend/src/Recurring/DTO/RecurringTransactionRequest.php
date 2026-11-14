<?php

namespace App\Recurring\DTO;

use App\Core\Validation\DateValue;
use App\Core\Validation\Decimal;
use App\Core\Validation\ViolationCode;
use App\Entity\InvestmentPlanMode;
use App\Entity\RecurrenceMode;
use App\Entity\RecurrenceMonthDay;
use App\Entity\RecurrenceUnit;
use App\Entity\WeekendShift;
use App\Transaction\DTO\CreateTransactionRequest;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A recurring transaction as the form sends it: the transaction it creates
 * (the same fields as CreateTransactionRequest, sides and classification by
 * id or by a new name), when it falls and what happens then.
 *
 * A recurring purchase (PAC) sends `instrumentId` too: the investment
 * account is `sourceAccountId`, `planMode` says how it buys, `amount` each
 * time (`quantity` instead in units mode) and `fees`.
 */
final readonly class RecurringTransactionRequest
{
    /**
     * @param string[]       $tagIds
     * @param string[]       $newTagNames
     * @param list<int>|null $months
     * @param list<array{from: string, amount: string}> $amountChanges
     */
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 255)]
        public ?string $description,

        /** Required, except for a purchase of a fixed number of units. */
        #[Assert\Positive]
        #[Decimal]
        public ?string $amount,

        #[Assert\NotBlank]
        #[DateValue]
        public string $startDate,

        #[Assert\Uuid]
        public ?string $sourceAccountId = null,

        #[Assert\Uuid]
        public ?string $destinationAccountId = null,

        #[Assert\Length(max: 255, normalizer: 'trim')]
        public ?string $newSourceAccountName = null,

        #[Assert\Length(max: 255, normalizer: 'trim')]
        public ?string $newDestinationAccountName = null,

        #[Assert\Positive]
        #[Decimal]
        public ?string $destinationAmount = null,

        #[Assert\Uuid]
        public ?string $categoryId = null,

        #[Assert\Length(max: 255, normalizer: 'trim')]
        public ?string $newCategoryName = null,

        #[Assert\Count(max: 50)]
        #[Assert\All([new Assert\Type('string'), new Assert\Uuid()])]
        public array $tagIds = [],

        #[Assert\Count(max: 50)]
        #[Assert\All([new Assert\Type('string'), new Assert\NotBlank(normalizer: 'trim'), new Assert\Length(max: 255, normalizer: 'trim')])]
        public array $newTagNames = [],

        #[Assert\Length(max: 10000)]
        public ?string $notes = null,

        public RecurrenceUnit $unit = RecurrenceUnit::Month,

        #[Assert\Range(min: 1, max: 366)]
        public int $interval = 1,

        public RecurrenceMonthDay $monthDay = RecurrenceMonthDay::Day,

        public WeekendShift $weekendShift = WeekendShift::None,

        /** Only these months (1-12); null or all twelve for every month. */
        #[Assert\Count(min: 1, max: 12)]
        #[Assert\All([new Assert\Type('int'), new Assert\Range(min: 1, max: 12)])]
        public ?array $months = null,

        #[DateValue]
        public ?string $endDate = null,

        /** How many times in all, from the start date. */
        #[Assert\Range(min: 1, max: 10000)]
        public ?int $count = null,

        #[Assert\Regex('/^([01]\d|2[0-3]):[0-5]\d$/')]
        public string $time = '12:00',

        /** Where it happens (IANA); the user's own when left out. */
        #[Assert\Timezone]
        public ?string $timezone = null,

        public RecurrenceMode $mode = RecurrenceMode::Auto,

        /** Entered by hand: days after its day before the user is reminded. */
        #[Assert\Range(min: 0, max: 30)]
        public int $reminderDays = 3,

        /** Entered by hand: expect the average of the latest this many occurrences; null for the amount. */
        #[Assert\Range(min: 1, max: 24)]
        public ?int $estimateCount = null,

        /** Planned changes of the amount: from that day on, that amount. */
        #[Assert\Count(max: 50)]
        #[Assert\All([new Assert\Collection([
            'from' => [new Assert\NotBlank(), new DateValue()],
            'amount' => [new Assert\NotBlank(), new Assert\Positive(), new Decimal()],
        ])])]
        public array $amountChanges = [],

        /** A recurring purchase (PAC): what it buys. */
        #[Assert\Uuid]
        public ?string $instrumentId = null,

        public ?InvestmentPlanMode $planMode = null,

        /** Plan mode units: how many each time. */
        #[Assert\Positive]
        #[Decimal(precision: 20, scale: 6)]
        public ?string $quantity = null,

        /** The broker's fee on each purchase. */
        #[Assert\PositiveOrZero]
        #[Decimal]
        public ?string $fees = null,

        /** New ones only: the transaction it is made from, which counts as its first occurrence. */
        #[Assert\Uuid]
        public ?string $fromTransactionId = null,

        /** New recurring purchases only: the buy it is made from, which counts as its first occurrence. */
        #[Assert\Uuid]
        public ?string $fromTradeId = null,
    ) {
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->endDate !== null && $this->endDate < $this->startDate) {
            $context->buildViolation('The end must not come before the start.')
                ->atPath('endDate')
                ->setCode(ViolationCode::OUT_OF_RANGE)
                ->addViolation();
        }
        $required = fn (string $path) => $context->buildViolation('This value should not be blank.')
            ->atPath($path)
            ->setCode(ViolationCode::REQUIRED)
            ->addViolation();
        if ($this->isPurchase()) {
            if ($this->planMode === null) {
                $required('planMode');
            }
            if ($this->sourceAccountId === null) {
                $required('sourceAccountId');
            }
        }
        if ($this->planMode === InvestmentPlanMode::Units && $this->isPurchase()) {
            if ($this->quantity === null || $this->quantity === '') {
                $required('quantity');
            }
        } elseif ($this->amount === null || $this->amount === '') {
            $required('amount');
        }
    }

    public function isPurchase(): bool
    {
        return $this->instrumentId !== null && $this->instrumentId !== '';
    }

    /** The months as stored: null when every month is in. */
    public function normalizedMonths(): ?array
    {
        if ($this->months === null) {
            return null;
        }
        $months = array_values(array_unique($this->months));
        sort($months);

        return \count($months) === 12 ? null : $months;
    }

    /** The transaction it creates, for the checks a transaction gets (sides, category, tags). */
    public function template(): CreateTransactionRequest
    {
        return new CreateTransactionRequest(
            description: $this->description,
            sourceAccountId: $this->sourceAccountId,
            destinationAccountId: $this->destinationAccountId,
            occurredAt: $this->startDate.'T'.$this->time.':00',
            amount: $this->amount ?? '0',
            destinationAmount: $this->destinationAmount,
            categoryId: $this->categoryId,
            tagIds: $this->tagIds,
            notes: $this->notes,
            newSourceAccountName: $this->newSourceAccountName,
            newDestinationAccountName: $this->newDestinationAccountName,
            newCategoryName: $this->newCategoryName,
            newTagNames: $this->newTagNames,
        );
    }
}
