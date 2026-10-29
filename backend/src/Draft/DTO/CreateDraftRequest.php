<?php

namespace App\Draft\DTO;

use App\Core\Validation\DateValue;
use App\Core\Validation\Decimal;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Something that may be a transaction, with what is known of it: an amount
 * and when, at least. `ifExists` says what to do when an identical
 * transaction is there already (same account when given, same amount to the
 * cent, within a day): ask the user (`review`), leave it (`skip`), add this
 * one's text to its notes (`annotate`) or make the draft anyway (`create`).
 */
final readonly class CreateDraftRequest
{
    public const IF_EXISTS = ['review', 'skip', 'annotate', 'create'];

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Positive]
        #[Decimal]
        public string $amount,

        #[Assert\NotBlank]
        #[DateValue(withTime: true)]
        public string $occurredAt,

        #[Assert\Length(max: 255)]
        public ?string $description = null,

        /** "out" (spent), "in" (received), or left out. */
        #[Assert\Choice(choices: ['out', 'in'])]
        public ?string $direction = null,

        #[Assert\Timezone]
        public ?string $timezone = null,

        #[Assert\Uuid]
        public ?string $sourceAccountId = null,

        #[Assert\Uuid]
        public ?string $destinationAccountId = null,

        #[Assert\Length(max: 10000)]
        public ?string $notes = null,

        /** What arrived as it arrived, e.g. a notification's text. */
        #[Assert\Length(max: 4000)]
        public ?string $rawText = null,

        /** Who sends it, e.g. "android"; "api" when left out. */
        #[Assert\Regex('/^[a-z][a-z0-9_-]{1,19}$/')]
        public ?string $source = null,

        /** Which one, e.g. the bank's app. */
        #[Assert\Length(max: 120)]
        public ?string $sourceName = null,

        #[Assert\Choice(choices: self::IF_EXISTS)]
        public string $ifExists = 'review',
    ) {
    }
}
