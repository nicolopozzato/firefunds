<?php

namespace App\Entity;

use App\Repository\UserRepository;
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
     * IANA timezone identifier (e.g. "Europe/Rome"), used to bucket transactions
     * into calendar days (balance history, etc.) by the user's own local day
     * rather than the UTC day the underlying timestamp happens to fall on.
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
     * Percentage applied to capital gains to estimate the tax owed if a
     * position were sold today (Italy: 26). An instrument can set its own
     * (e.g. a fund holding white-list government bonds).
     */
    #[ORM\Column(type: 'decimal', precision: 7, scale: 4, options: ['default' => '26.0000'])]
    private string $capitalGainsTaxRate = '26.0000';

    #[ORM\Column]
    #[Assert\Positive]
    private int $pageSize = 50;

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

    public function getUserIdentifier(): string
    {
        return $this->email;
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

    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    public function setPageSize(int $pageSize): static
    {
        $this->pageSize = $pageSize;
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
