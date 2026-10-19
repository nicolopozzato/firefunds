<?php

namespace App\Transaction\DTO;

use App\Core\Validation\ViolationCode;
use App\Core\Validation\Decimal;
use App\Core\Validation\DateValue;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final readonly class CreateTransactionRequest
{
    public function __construct(
        // Nullable only so a missing field gets this message instead of a type error; it's still required.
        #[Assert\NotBlank(message: 'Description is required', normalizer: 'trim')]
        #[Assert\Length(max: 255)]
        public ?string $description,

        /** Null for money coming from outside the tracked accounts ("external"). */
        #[Assert\Uuid]
        public ?string $sourceAccountId,

        /** Null for money leaving the tracked accounts ("external"). */
        #[Assert\Uuid]
        public ?string $destinationAccountId,

        #[Assert\NotBlank(message: 'Date is required')]
        #[DateValue(withTime: true)]
        public string $occurredAt,

        #[Assert\NotBlank(message: 'Amount is required')]
        #[Assert\Positive(message: 'Amount must be greater than zero')]
        #[Decimal]
        public string $amount,

        #[Assert\Positive]
        #[Decimal]
        public ?string $foreignAmount = null,

        #[Assert\Length(exactly: 3)]
        public ?string $foreignCurrency = null,

        #[Assert\Uuid]
        public ?string $categoryId = null,

        /** @var string[] */
        #[Assert\Count(max: 50)]
        #[Assert\All([new Assert\Type('string'), new Assert\Uuid()])]
        public array $tagIds = [],

        #[Assert\Length(max: 10000)]
        public ?string $notes = null,

        /**
         * Where it happened (IANA, e.g. "Europe/Rome"): the zone occurredAt is
         * written in. Null = unchanged on an edit, the user's own on a new one.
         */
        #[Assert\Timezone]
        public ?string $timezone = null,
    ) {
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->sourceAccountId === null && $this->destinationAccountId === null) {
            $context->buildViolation('At least one of source and destination account is required.')
                ->atPath('destinationAccountId')
                ->setCode(ViolationCode::TRANSACTION_ACCOUNT_REQUIRED)
                ->addViolation();
        } elseif (strtolower((string) $this->sourceAccountId) === strtolower((string) $this->destinationAccountId)) {
            $context->buildViolation('Source and destination accounts must be different.')
                ->atPath('destinationAccountId')
                ->setCode(ViolationCode::TRANSACTION_ACCOUNTS_SAME)
                ->addViolation();
        }

        if ($this->foreignAmount !== null && $this->foreignCurrency === null) {
            $context->buildViolation('Foreign currency is required when a foreign amount is set.')
                ->atPath('foreignCurrency')
                ->setCode(ViolationCode::FOREIGN_CURRENCY_REQUIRED)
                ->addViolation();
        }
    }
}
