<?php

namespace App\Account\DTO;

use App\Entity\AssetAccountKind;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Filters of the accounts list. Both default to "everything", so callers that
 * need every account (e.g. pickers) are unaffected.
 */
final readonly class AccountListQuery
{
    public function __construct(
        #[Assert\Choice(choices: ['all', 'active', 'inactive'])]
        public string $status = 'all',

        /** Asset accounts only: checking or investment; null = both. */
        #[Assert\Choice(choices: ['checking', 'investment'])]
        public ?string $kind = null,
    ) {
    }

    /** true / false for active / inactive accounts only, null for all. */
    public function isActive(): ?bool
    {
        return match ($this->status) {
            'active' => true,
            'inactive' => false,
            default => null,
        };
    }

    public function assetKind(): ?AssetAccountKind
    {
        return $this->kind !== null ? AssetAccountKind::from($this->kind) : null;
    }
}
