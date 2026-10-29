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

        /**
         * A transfer between asset accounts in different currencies: what
         * arrived on the destination account, in its currency. Otherwise left out.
         */
        #[Assert\Positive]
        #[Decimal]
        public ?string $destinationAmount = null,

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
         * A side the form names instead of picking (written in the form,
         * created on save): a revenue account as the source, an expense
         * account as the destination. The user's one with that name is used
         * if there is one, ignoring case; else it is created together with
         * the transaction, never on its own. In place of the side's id.
         */
        #[Assert\Length(max: 255, normalizer: 'trim')]
        public ?string $newSourceAccountName = null,

        #[Assert\Length(max: 255, normalizer: 'trim')]
        public ?string $newDestinationAccountName = null,

        /** As the new sides: a category by name, used if it exists, else created with the transaction. */
        #[Assert\Length(max: 255, normalizer: 'trim')]
        public ?string $newCategoryName = null,

        /** As the new sides: tags by name, besides tagIds. */
        #[Assert\Count(max: 50)]
        #[Assert\All([new Assert\Type('string'), new Assert\NotBlank(normalizer: 'trim'), new Assert\Length(max: 255, normalizer: 'trim')])]
        public array $newTagNames = [],

        /**
         * Where it happened (IANA, e.g. "Europe/Rome"): the zone occurredAt is
         * written in. Null = unchanged on an edit, the user's own on a new one.
         */
        #[Assert\Timezone]
        public ?string $timezone = null,

        /**
         * For other programs (the API): when an identical transaction is
         * there already (same account, same amount, within a day), make it
         * anyway (`create`, as when left out), leave it (`skip`), add this
         * one's text to its notes (`annotate`) or ask the user with a draft
         * (`review`). See DraftService::incomingTransaction().
         */
        #[Assert\Choice(choices: ['create', 'skip', 'annotate', 'review'])]
        public ?string $ifExists = null,
    ) {
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        foreach (['sourceAccountId' => 'newSourceAccountName', 'destinationAccountId' => 'newDestinationAccountName', 'categoryId' => 'newCategoryName'] as $id => $name) {
            if ($this->{$id} !== null && $this->hasName($this->{$name})) {
                $context->buildViolation('Either an id or a new name, not both.')->atPath($name)->addViolation();
            }
        }

        $hasSource = $this->sourceAccountId !== null || $this->hasName($this->newSourceAccountName);
        $hasDestination = $this->destinationAccountId !== null || $this->hasName($this->newDestinationAccountName);
        if (!$hasSource && !$hasDestination) {
            $context->buildViolation('At least one of source and destination account is required.')
                ->atPath('destinationAccountId')
                ->setCode(ViolationCode::TRANSACTION_ACCOUNT_REQUIRED)
                ->addViolation();
        } elseif ($this->sourceAccountId !== null && strtolower($this->sourceAccountId) === strtolower((string) $this->destinationAccountId)) {
            $context->buildViolation('Source and destination accounts must be different.')
                ->atPath('destinationAccountId')
                ->setCode(ViolationCode::TRANSACTION_ACCOUNTS_SAME)
                ->addViolation();
        }

        if ($this->hasName($this->newSourceAccountName) && $this->hasName($this->newDestinationAccountName)) {
            // A revenue account to an expense account: never a valid pair.
            $context->buildViolation('A transaction always has an asset account on one side.')
                ->atPath('newDestinationAccountName')
                ->setCode(ViolationCode::TRANSACTION_ACCOUNT_REQUIRED)
                ->addViolation();
        }

        if ($this->foreignAmount !== null && $this->foreignCurrency === null) {
            $context->buildViolation('Foreign currency is required when a foreign amount is set.')
                ->atPath('foreignCurrency')
                ->setCode(ViolationCode::FOREIGN_CURRENCY_REQUIRED)
                ->addViolation();
        }
    }

    public function hasName(?string $name): bool
    {
        return $name !== null && trim($name) !== '';
    }
}
