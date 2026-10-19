<?php

namespace App\Auth\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdatePreferencesRequest
{
    /** 1.234,56 / 1,234.56 / 1'234.56 / 1 234,56 */
    public const array NUMBER_FORMATS = ['it-IT', 'en-US', 'de-CH', 'fr-FR'];

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['it', 'en'])]
        public string $locale,

        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['light', 'dark', 'system'])]
        public string $theme,

        #[Assert\Positive]
        public int $pageSize,

        #[Assert\NotBlank]
        #[Assert\Choice(callback: [\DateTimeZone::class, 'listIdentifiers'])]
        public string $timezone,

        #[Assert\NotBlank]
        #[Assert\Choice(choices: self::NUMBER_FORMATS)]
        public string $numberFormat,

        /** Percentage, 0-100. Optional: left out = unchanged. */
        #[Assert\Range(min: 0, max: 100)]
        public ?string $capitalGainsTaxRate = null,
    ) {
    }
}
