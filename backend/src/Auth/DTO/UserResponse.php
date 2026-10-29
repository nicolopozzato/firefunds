<?php

namespace App\Auth\DTO;

use App\Entity\User;

final readonly class UserResponse
{
    public function __construct(
        public string $id,
        public string $firstName,
        public string $lastName,
        public string $email,
        public array $roles,
        public bool $isAdmin,
        public string $locale,
        public string $theme,
        public int $pageSize,
        public string $timezone,
        public string $numberFormat,
        /** The currency (code) shown where different currencies are added up. */
        public string $currency,
        /** Percentage used to estimate the tax on capital gains (see User::$capitalGainsTaxRate). */
        public string $capitalGainsTaxRate,
        /** An administrator set the password: the app asks for a new one before anything else. */
        public bool $mustChangePassword,
        public string $density,
        public string $startPage,
        public string $startPeriod,
        /** Null when none, or the one chosen was deleted or deactivated. */
        public ?string $defaultAccountId,
        /** @var list<array{key: string, visible: bool}> */
        public array $dashboardSections,
        public string $dashboardTrendView,
        public string $dashboardInvestmentUnit,
        /** Push: the daily summary of reminders, and server problems (administrators). */
        public bool $pushReminders,
        public bool $pushSystem,
        public bool $pushDrafts,
        public string $pushSummaryTime,
    ) {
    }

    public static function fromEntity(User $user): self
    {
        return new self(
            id: $user->getId(),
            firstName: $user->getFirstName(),
            lastName: $user->getLastName(),
            email: $user->getEmail(),
            roles: $user->getRoles(),
            isAdmin: $user->isAdmin(),
            locale: $user->getLocale(),
            theme: $user->getTheme()->value,
            pageSize: $user->getPageSize(),
            timezone: $user->getTimezone(),
            numberFormat: $user->getNumberFormat(),
            currency: $user->getCurrency(),
            capitalGainsTaxRate: $user->getCapitalGainsTaxRate(),
            mustChangePassword: $user->mustChangePassword(),
            density: $user->getDensity(),
            startPage: $user->getStartPage(),
            startPeriod: $user->getStartPeriod(),
            defaultAccountId: $user->getUsableDefaultAccount()?->getId()->toRfc4122(),
            dashboardSections: $user->getDashboardSections(),
            dashboardTrendView: $user->getDashboardTrendView(),
            dashboardInvestmentUnit: $user->getDashboardInvestmentUnit(),
            pushReminders: $user->wantsPushReminders(),
            pushSystem: $user->wantsPushSystem(),
            pushDrafts: $user->wantsPushDrafts(),
            pushSummaryTime: $user->getPushSummaryTime(),
        );
    }
}
