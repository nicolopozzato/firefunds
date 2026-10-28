<?php

namespace App\Account\DTO;

use App\Core\Validation\DateValue;
use App\Core\Validation\Decimal;
use App\Core\Validation\ViolationCode;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The balance the bank shows at the end of a day. With `settle`, a
 * difference from FireFunds' balance is recorded as an expense (less in the
 * bank) or an income (more), on that day, with this description and category.
 */
final readonly class ReconcileAccountRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[DateValue]
        public string $day,

        #[Assert\NotBlank]
        #[Decimal]
        public string $balance,

        public bool $settle = false,

        #[Assert\Length(max: 255)]
        public ?string $description = null,

        #[Assert\Uuid]
        public ?string $categoryId = null,
    ) {
    }

    /** The transaction settling a difference is like any other: it needs a description. */
    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->settle && trim((string) $this->description) === '') {
            $context->buildViolation('Description is required')
                ->atPath('description')
                ->setCode(ViolationCode::REQUIRED)
                ->addViolation();
        }
    }
}
