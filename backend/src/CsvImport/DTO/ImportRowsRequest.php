<?php

namespace App\CsvImport\DTO;

use App\Core\Validation\DateValue;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A file's rows as the app read them in the browser: a day, a signed amount
 * (negative: out of the row's account), what the file wrote, and the other
 * side when the file has a column for it. `accountId` is the user's account
 * the row is on; without one (an account the import will create) the row
 * gets suggestions only, nothing to be a duplicate of.
 */
final readonly class ImportRowsRequest
{
    /**
     * @param list<array{date: string, amount: string, accountId?: ?string, description?: ?string, counterparty?: ?string}> $rows
     */
    public function __construct(
        #[Assert\Count(min: 1, max: CommitImportRequest::MAX_ROWS)]
        #[Assert\All([new Assert\Collection(fields: [
            'date' => [new Assert\NotBlank(), new DateValue()],
            'amount' => [new Assert\NotBlank(), new Assert\Regex('/^-?\d{1,15}(\.\d{1,4})?$/')],
            'accountId' => new Assert\Optional([new Assert\Uuid()]),
            'description' => new Assert\Optional([new Assert\Length(max: 1000)]),
            'counterparty' => new Assert\Optional([new Assert\Length(max: 255)]),
        ], allowExtraFields: true)])]
        public array $rows,
    ) {
    }
}
