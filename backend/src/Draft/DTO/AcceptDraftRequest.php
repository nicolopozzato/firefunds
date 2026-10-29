<?php

namespace App\Draft\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A draft becoming transactions: one, or several when it is split (each its
 * own amount, accounts, category; the user decides the amounts, no total is
 * enforced).
 */
final readonly class AcceptDraftRequest
{
    /**
     * @param list<array<string, mixed>> $parts each as CreateTransactionRequest takes it (TransactionPayloads reads them)
     */
    public function __construct(
        #[Assert\Count(min: 1, max: 20)]
        #[Assert\All([new Assert\Type('array')])]
        public array $parts,
    ) {
    }
}
