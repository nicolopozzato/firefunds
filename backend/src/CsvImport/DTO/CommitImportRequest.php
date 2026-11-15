<?php

namespace App\CsvImport\DTO;

use App\Core\Validation\DateValue;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * What the user kept after the preview, recorded as one import:
 * - `accounts`: asset accounts to create first (named in the file, not in
 *   FireFunds yet), each with a `key` the rows use in place of an id;
 * - `rows`: each the transaction it becomes (as the form sends it), its new
 *   accounts by key, and, when it is a recurring transaction's occurrence,
 *   which one and which day;
 * - `adjustments`: other changes to an account's balance (a Firefly
 *   reconciliation), on an account by id or by key.
 */
final readonly class CommitImportRequest
{
    public const int MAX_ROWS = 10000;

    /**
     * @param list<array{key: string, name: string, currencyCode: string, openingBalance?: ?string, openingBalanceDate?: ?string}> $accounts
     * @param list<array{transaction: array<string, mixed>, sourceAccountKey?: ?string, destinationAccountKey?: ?string, recurringId?: ?string, recurringDay?: ?string}> $rows
     * @param list<array{accountId?: ?string, accountKey?: ?string, date: string, amount: string, description?: ?string}> $adjustments
     */
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 255)]
        public string $fileName,

        #[Assert\Count(max: self::MAX_ROWS)]
        #[Assert\All([new Assert\Collection(fields: [
            'transaction' => [new Assert\NotBlank(), new Assert\Type('array')],
            'sourceAccountKey' => new Assert\Optional([new Assert\Length(max: 50)]),
            'destinationAccountKey' => new Assert\Optional([new Assert\Length(max: 50)]),
            'recurringId' => new Assert\Optional([new Assert\Uuid()]),
            'recurringDay' => new Assert\Optional([new DateValue()]),
        ])])]
        public array $rows = [],

        #[Assert\Count(max: 500)]
        #[Assert\All([new Assert\Collection(fields: [
            'key' => [new Assert\NotBlank(), new Assert\Length(max: 50)],
            'name' => [new Assert\NotBlank(normalizer: 'trim'), new Assert\Length(max: 255)],
            'currencyCode' => [new Assert\NotBlank(), new Assert\Length(exactly: 3)],
            'openingBalance' => new Assert\Optional([new Assert\Regex('/^-?\d{1,15}(\.\d{1,4})?$/')]),
            'openingBalanceDate' => new Assert\Optional([new DateValue()]),
        ])])]
        public array $accounts = [],

        #[Assert\Count(max: 5000)]
        #[Assert\All([new Assert\Collection(fields: [
            'accountId' => new Assert\Optional([new Assert\Uuid()]),
            'accountKey' => new Assert\Optional([new Assert\Length(max: 50)]),
            'date' => [new Assert\NotBlank(), new DateValue()],
            'amount' => [new Assert\NotBlank(), new Assert\Regex('/^-?\d{1,15}(\.\d{1,4})?$/')],
            'description' => new Assert\Optional([new Assert\Length(max: 255)]),
        ])])]
        public array $adjustments = [],
    ) {
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->rows === [] && $this->adjustments === [] && $this->accounts === []) {
            $context->buildViolation('Nothing to import.')->atPath('rows')->addViolation();
        }
    }
}
