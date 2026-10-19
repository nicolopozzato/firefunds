<?php

namespace App\Transaction\DTO;

use App\Core\Validation\ViolationCode;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final readonly class CreateTransactionRequest
{
    public function __construct(
        // Nullable only so a missing field gets this message instead of a type error; it's still required.
        #[Assert\NotBlank(message: 'Description is required', normalizer: 'trim')]
        public ?string $description,

        /** Null for money coming from outside the tracked accounts ("external"). */
        public ?string $sourceAccountId,

        /** Null for money leaving the tracked accounts ("external"). */
        public ?string $destinationAccountId,

        #[Assert\NotBlank(message: 'Date is required')]
        public string $occurredAt,

        #[Assert\NotBlank(message: 'Amount is required')]
        #[Assert\Positive(message: 'Amount must be greater than zero')]
        public string $amount,

        public ?string $foreignAmount = null,

        #[Assert\Length(exactly: 3)]
        public ?string $foreignCurrency = null,

        public ?string $categoryId = null,

        /** @var string[] */
        public array $tagIds = [],

        public ?string $notes = null,
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
        } elseif ($this->sourceAccountId === $this->destinationAccountId) {
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
