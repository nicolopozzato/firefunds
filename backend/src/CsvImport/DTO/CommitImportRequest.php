<?php

namespace App\CsvImport\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The rows the user kept after the preview, each the transaction it becomes
 * (as the form sends it) and, when it is a recurring transaction's
 * occurrence, which one and which day.
 */
final readonly class CommitImportRequest
{
    /**
     * @param list<array{transaction: array<string, mixed>, recurringId?: ?string, recurringDay?: ?string}> $rows
     */
    public function __construct(
        #[Assert\Count(min: 1, max: 5000)]
        #[Assert\All([new Assert\Collection(fields: [
            'transaction' => [new Assert\NotBlank(), new Assert\Type('array')],
            'recurringId' => new Assert\Optional([new Assert\Uuid()]),
            'recurringDay' => new Assert\Optional([new Assert\Date()]),
        ])])]
        public array $rows,
    ) {
    }
}
