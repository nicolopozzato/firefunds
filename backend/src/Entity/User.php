<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const array DENSITIES = ['compact', 'comfortable', 'spacious'];
    public const array START_PAGES = ['dashboard', 'transactions', 'investments', 'reports'];
    public const array START_PERIODS = ['last', 'currentMonth', 'last30Days', 'last60Days', 'currentYear'];
    /** In their default order: the summary cards, the chart, the three rankings, the accounts' boxes. */
    public const array DASHBOARD_SECTIONS = ['summary', 'trend', 'expensesByCategory', 'expenseAccounts', 'revenueAccounts', 'accounts'];
    public const array DASHBOARD_TREND_VIEWS = ['accounts', 'investments'];
    public const array DASHBOARD_INVESTMENT_UNITS = ['percent', 'money'];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 100)]
    private string $firstName;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 100)]
    private string $lastName;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email;

    #[ORM\Column]
    private string $password;

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(length: 5)]
    private string $locale = 'en';

    /**
     * IANA timezone identifier (e.g. "Europe/Rome"): where the user is. A new
     * transaction is written in it unless another zone is sent (see
     * App\Entity\OccursLocally), and "today" and "this month" follow it.
     */
    #[ORM\Column(length: 64)]
    private string $timezone = 'Europe/Rome';

    #[ORM\Column(length: 10, enumType: UserTheme::class)]
    private UserTheme $theme = UserTheme::System;

    /**
     * BCP 47 tag whose digit grouping and decimal separator the frontend uses
     * for every number (e.g. "it-IT" -> 1.234,56, "en-US" -> 1,234.56).
     * Independent of `locale`, which only picks the interface language.
     */
    #[ORM\Column(length: 10)]
    private string $numberFormat = 'it-IT';

    /**
     * The currency (ISO 4217 code) amounts are shown in wherever different
     * currencies are added up: dashboard, reports, net worth. Also what a new
     * account or instrument starts with.
     */
    #[ORM\Column(length: 3)]
    private string $currency = 'EUR';

    /**
     * Percentage applied to capital gains to estimate the tax owed if a
     * position were sold today (Italy: 26). An instrument can set its own
     * (e.g. a fund holding white-list government bonds).
     */
    #[ORM\Column(type: 'decimal', precision: 7, scale: 4, options: ['default' => '26.0000'])]
    private string $capitalGainsTaxRate = '26.0000';

    #[ORM\Column]
    #[Assert\Positive]
    private int $pageSize = 50;

    /** How much fits on screen on larger screens: compact, comfortable or spacious. */
    #[ORM\Column(length: 12, options: ['default' => 'comfortable'])]
    private string $density = 'comfortable';

    /** Push: the daily summary of what to enter (reminders), at `pushSummaryTime` where the user is. */
    #[ORM\Column(options: ['default' => true])]
    private bool $pushReminders = true;

    /** Push: a new draft to review, right away. */
    #[ORM\Column(options: ['default' => true])]
    private bool $pushDrafts = true;

    /** Push: problems on the server, right away (administrators only). */
    #[ORM\Column(options: ['default' => true])]
    private bool $pushSystem = true;

    #[ORM\Column(length: 5, options: ['default' => '09:00'])]
    private string $pushSummaryTime = '09:00';

    /** The day (where the user is) the last summary was sent: one a day. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $pushSummarySentOn = null;

    /** The page the app opens on (after login, or at "/"). */
    #[ORM\Column(length: 20, options: ['default' => 'dashboard'])]
    private string $startPage = 'dashboard';

    /**
     * The period the app opens with: a preset of the period picker, or
     * "last" for the one chosen last time on that device.
     */
    #[ORM\Column(length: 20, options: ['default' => 'last'])]
    private string $startPeriod = 'last';

    /**
     * The asset account a new transaction starts from (source of a withdrawal
     * or transfer, destination of a deposit). Ignored while deleted or inactive.
     */
    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Account $defaultAccount = null;

    /**
     * The dashboard's sections in order, each shown or not:
     * list of {key, visible} (keys in DASHBOARD_SECTIONS). Null: the default.
     *
     * @var list<array{key: string, visible: bool}>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $dashboardSections = null;

    /** The dashboard chart's view: accounts or investments. */
    #[ORM\Column(length: 12, options: ['default' => 'accounts'])]
    private string $dashboardTrendView = 'accounts';

    /** The investments view's unit: percent (price change) or money (value held). */
    #[ORM\Column(length: 8, options: ['default' => 'percent'])]
    private string $dashboardInvestmentUnit = 'percent';

    /**
     * Set by an administrator: the user can't log in and every session ends
     * (App\Auth\Security\UserChecker). Not a delete: the data stays, and
     * an administrator can turn the user back on.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $disabledAt = null;

    /**
     * The password was set by an administrator, who asked for a new one at
     * the next login: until then the API answers only what changing it needs
     * (App\Auth\EventListener\PasswordChangeRequiredListener).
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $mustChangePassword = false;

    /**
     * When the user last used the app (UTC): a login, or any request at most
     * LastSeenListener::PRECISION after the previous record. Null: never.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;
        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;
        return $this;
    }

    public function getFullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = strtolower($email);
        return $this;
    }

    /**
     * The id, never the email: JWTs and refresh tokens carry this, and an
     * email can change hands (a token naming an address would then let in
     * whoever holds it next). Login still finds the user by email, through
     * the `users_by_email` provider (security.yaml).
     */
    public function getUserIdentifier(): string
    {
        return $this->id->toRfc4122();
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function isAdmin(): bool
    {
        return in_array('ROLE_ADMIN', $this->roles, true);
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function eraseCredentials(): void
    {
        // Clear any temporary sensitive data
    }

    public function wantsPushReminders(): bool
    {
        return $this->pushReminders;
    }

    public function setPushReminders(bool $on): static
    {
        $this->pushReminders = $on;

        return $this;
    }

    public function wantsPushDrafts(): bool
    {
        return $this->pushDrafts;
    }

    public function setPushDrafts(bool $on): static
    {
        $this->pushDrafts = $on;

        return $this;
    }

    public function wantsPushSystem(): bool
    {
        return $this->pushSystem;
    }

    public function setPushSystem(bool $on): static
    {
        $this->pushSystem = $on;

        return $this;
    }

    public function getPushSummaryTime(): string
    {
        return $this->pushSummaryTime;
    }

    public function setPushSummaryTime(string $time): static
    {
        $this->pushSummaryTime = $time;

        return $this;
    }

    public function getPushSummarySentOn(): ?\DateTimeImmutable
    {
        return $this->pushSummarySentOn;
    }

    public function setPushSummarySentOn(\DateTimeImmutable $day): static
    {
        $this->pushSummarySentOn = $day;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;
        return $this;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): static
    {
        $this->timezone = $timezone;
        return $this;
    }

    public function getTheme(): UserTheme
    {
        return $this->theme;
    }

    public function setTheme(UserTheme $theme): static
    {
        $this->theme = $theme;
        return $this;
    }

    public function getCapitalGainsTaxRate(): string
    {
        return $this->capitalGainsTaxRate;
    }

    public function setCapitalGainsTaxRate(string $capitalGainsTaxRate): static
    {
        $this->capitalGainsTaxRate = $capitalGainsTaxRate;

        return $this;
    }

    public function getNumberFormat(): string
    {
        return $this->numberFormat;
    }

    public function setNumberFormat(string $numberFormat): static
    {
        $this->numberFormat = $numberFormat;
        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = strtoupper($currency);
        return $this;
    }

    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    public function setPageSize(int $pageSize): static
    {
        $this->pageSize = $pageSize;
        return $this;
    }

    public function getDensity(): string
    {
        return $this->density;
    }

    public function setDensity(string $density): static
    {
        $this->density = $density;
        return $this;
    }

    public function getStartPage(): string
    {
        return $this->startPage;
    }

    public function setStartPage(string $startPage): static
    {
        $this->startPage = $startPage;
        return $this;
    }

    public function getStartPeriod(): string
    {
        return $this->startPeriod;
    }

    public function setStartPeriod(string $startPeriod): static
    {
        $this->startPeriod = $startPeriod;
        return $this;
    }

    public function getDefaultAccount(): ?Account
    {
        return $this->defaultAccount;
    }

    /** The default account if it can still be used: not deleted, active. */
    public function getUsableDefaultAccount(): ?Account
    {
        $account = $this->defaultAccount;
        try {
            return $account !== null && $account->getDeletedAt() === null && $account->isActive() ? $account : null;
        } catch (EntityNotFoundException) {
            // Deleted: the soft delete filter hides it from the proxy.
            return null;
        }
    }

    public function setDefaultAccount(?Account $defaultAccount): static
    {
        $this->defaultAccount = $defaultAccount;
        return $this;
    }

    /**
     * Every section, in the user's order: the saved ones first (unknown keys
     * dropped), then any section added since, where it is by default.
     *
     * @return list<array{key: string, visible: bool}>
     */
    public function getDashboardSections(): array
    {
        $sections = [];
        foreach ($this->dashboardSections ?? [] as $section) {
            if (\in_array($section['key'] ?? null, self::DASHBOARD_SECTIONS, true) && !isset($sections[$section['key']])) {
                $sections[$section['key']] = ['key' => $section['key'], 'visible' => (bool) ($section['visible'] ?? true)];
            }
        }
        foreach (self::DASHBOARD_SECTIONS as $key) {
            $sections[$key] ??= ['key' => $key, 'visible' => true];
        }

        return array_values($sections);
    }

    /** @param list<array{key: string, visible: bool}> $dashboardSections */
    public function setDashboardSections(array $dashboardSections): static
    {
        $this->dashboardSections = $dashboardSections;
        return $this;
    }

    public function getDashboardTrendView(): string
    {
        return $this->dashboardTrendView;
    }

    public function setDashboardTrendView(string $dashboardTrendView): static
    {
        $this->dashboardTrendView = $dashboardTrendView;
        return $this;
    }

    public function getDashboardInvestmentUnit(): string
    {
        return $this->dashboardInvestmentUnit;
    }

    public function setDashboardInvestmentUnit(string $dashboardInvestmentUnit): static
    {
        $this->dashboardInvestmentUnit = $dashboardInvestmentUnit;
        return $this;
    }

    public function getDisabledAt(): ?\DateTimeImmutable
    {
        return $this->disabledAt;
    }

    public function isActive(): bool
    {
        return null === $this->disabledAt;
    }

    public function setDisabledAt(?\DateTimeImmutable $disabledAt): static
    {
        $this->disabledAt = $disabledAt;
        return $this;
    }

    public function mustChangePassword(): bool
    {
        return $this->mustChangePassword;
    }

    public function setMustChangePassword(bool $mustChangePassword): static
    {
        $this->mustChangePassword = $mustChangePassword;
        return $this;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(?\DateTimeImmutable $lastSeenAt): static
    {
        $this->lastSeenAt = $lastSeenAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
