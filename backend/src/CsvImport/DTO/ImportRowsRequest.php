<?php

namespace App\CsvImport\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A file's rows as the app read them in the browser, for one of the user's
 * accounts: a day, a signed amount (negative: out of the account), what the
 * bank wrote, and the other side when the file has a column for it.
 */
final readonly class ImportRowsRequest
{
    /**
     * @param list<array{date: string, amount: string, description?: ?string, counterparty?: ?string}> $rows
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $accountId,

        #[Assert\Count(min: 1, max: 5000)]
        #[Assert\All([new Assert\Collection(fields: [
            'date' => [new Assert\NotBlank(), new Assert\Date()],
            'amount' => [new Assert\NotBlank(), new Assert\Regex('/^-?\d{1,15}(\.\d{1,4})?$/')],
            'description' => new Assert\Optional([new Assert\Length(max: 1000)]),
            'counterparty' => new Assert\Optional([new Assert\Length(max: 255)]),
        ], allowExtraFields: true)])]
        public array $rows,
    ) {
    }
}
