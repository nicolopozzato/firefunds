<?php

namespace App\Auth\DTO;

use App\Core\Validation\Decimal;
use App\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdatePreferencesRequest
{
    /** 1.234,56 / 1,234.56 / 1'234.56 / 1 234,56 */
    public const array NUMBER_FORMATS = ['it-IT', 'en-US', 'de-CH', 'fr-FR'];

    /** Every preference is optional: left out = unchanged, so each can be saved on its own. */
    public function __construct(
        #[Assert\Choice(choices: ['it', 'en'])]
        public ?string $locale = null,

        #[Assert\Choice(choices: ['light', 'dark', 'system'])]
        public ?string $theme = null,

        #[Assert\Positive]
        public ?int $pageSize = null,

        #[Assert\Choice(callback: [\DateTimeZone::class, 'listIdentifiers'])]
        public ?string $timezone = null,

        #[Assert\Choice(choices: self::NUMBER_FORMATS)]
        public ?string $numberFormat = null,

        /** Percentage, 0-100. */
        #[Assert\Range(min: 0, max: 100)]
        #[Decimal(precision: 7, scale: 4)]
        public ?string $capitalGainsTaxRate = null,

        /** ISO 4217 code of the preferred currency. */
        #[Assert\Length(exactly: 3)]
        public ?string $currency = null,

        #[Assert\Choice(choices: User::DENSITIES)]
        public ?string $density = null,

        #[Assert\Choice(choices: User::START_PAGES)]
        public ?string $startPage = null,

        #[Assert\Choice(choices: User::START_PERIODS)]
        public ?string $startPeriod = null,

        /** An asset account of the user's; "" = none. */
        public ?string $defaultAccountId = null,

        /** Every section in order, each shown or not (User::DASHBOARD_SECTIONS). */
        #[Assert\All([
            new Assert\Collection(fields: [
                'key' => [new Assert\NotBlank(), new Assert\Choice(choices: User::DASHBOARD_SECTIONS)],
                'visible' => new Assert\Type('bool'),
            ]),
        ])]
        public ?array $dashboardSections = null,

        #[Assert\Choice(choices: User::DASHBOARD_TREND_VIEWS)]
        public ?string $dashboardTrendView = null,

        #[Assert\Choice(choices: User::DASHBOARD_INVESTMENT_UNITS)]
        public ?string $dashboardInvestmentUnit = null,

        public ?bool $pushReminders = null,

        public ?bool $pushSystem = null,

        public ?bool $pushDrafts = null,

        #[Assert\Regex('/^([01]\d|2[0-3]):[0-5]\d$/')]
        public ?string $pushSummaryTime = null,
    ) {
    }
}
