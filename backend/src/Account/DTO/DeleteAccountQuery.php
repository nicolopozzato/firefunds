<?php

namespace App\Account\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What to do with the transactions of an account being deleted: move them to
 * `transferTo`, or delete them too (`deleteTransactions`). Neither is needed
 * when the account has no transactions.
 */
final readonly class DeleteAccountQuery
{
    public function __construct(
        #[Assert\Uuid]
        public ?string $transferTo = null,
        public bool $deleteTransactions = false,
    ) {
    }
}
