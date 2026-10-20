<?php

namespace App\Account\DTO;

use App\Core\Validation\ViolationCode;
use App\Entity\AccountType;
use App\Entity\AssetAccountKind;
use App\Core\Validation\Decimal;
use App\Core\Validation\DateValue;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final readonly class CreateAccountRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['asset', 'expense', 'revenue'])]
        public string $type,

        #[Assert\NotBlank(message: 'Name is required')]
        #[Assert\Length(max: 255)]
        public string $name,

        #[Assert\Length(max: 34)]
        public ?string $iban = null,

        #[Assert\Length(max: 11)]
        public ?string $bic = null,

        #[Assert\Length(max: 64)]
        public ?string $accountNumber = null,

        #[Assert\Length(max: 10000)]
        public ?string $notes = null,

        public bool $isActive = true,

        #[Assert\Length(exactly: 3)]
        public ?string $currencyCode = null,

        #[Decimal]
        public ?string $openingBalance = null,

        #[DateValue]
        public ?string $openingBalanceDate = null,

        /**
         * Asset accounts: their other changes (AccountAdjustment), all of
         * them. Those not sent any more are deleted, those with an id updated,
         * the others created; null leaves them as they are.
         *
         * @var ?array<array{id?: ?string, date: string, amount: string, description?: ?string}>
         */
        #[AdjustmentList]
        public ?array $adjustments = null,

        #[Assert\Choice(choices: ['checking', 'investment'])]
        public ?string $assetKind = null,
    ) {
    }

    public function getAccountType(): AccountType
    {
        return AccountType::from($this->type);
    }

    public function getAssetAccountKind(): ?AssetAccountKind
    {
        return $this->assetKind !== null ? AssetAccountKind::from($this->assetKind) : null;
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->type === AccountType::Asset->value && $this->currencyCode === null) {
            $context->buildViolation('Currency is required for asset accounts.')
                ->atPath('currencyCode')
                ->setCode(ViolationCode::CURRENCY_REQUIRED_FOR_ASSET)
                ->addViolation();
        }

        if ($this->type === AccountType::Asset->value && $this->assetKind === null) {
            $context->buildViolation('Account kind is required for asset accounts.')
                ->atPath('assetKind')
                ->setCode(ViolationCode::KIND_REQUIRED_FOR_ASSET)
                ->addViolation();
        }
    }
}
