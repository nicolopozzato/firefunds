<?php

namespace App\Entity;

use App\Repository\AccountRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\Table(name: 'account')]
#[ORM\Index(columns: ['user_id', 'type'])]
#[ORM\HasLifecycleCallbacks]
class Account implements SoftDeletable
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20, enumType: AccountType::class)]
    private AccountType $type;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name;

    #[ORM\Column(length: 34, nullable: true)]
    #[Assert\Length(max: 34)]
    private ?string $iban = null;

    #[ORM\Column(length: 11, nullable: true)]
    #[Assert\Length(max: 11)]
    private ?string $bic = null;

    #[ORM\Column(length: 64, nullable: true)]
    #[Assert\Length(max: 64)]
    private ?string $accountNumber = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private bool $isActive = true;

    /** Whether the dashboard shows it (balance chart, per-account box): a user preference, asset accounts only. */
    #[ORM\Column(options: ['default' => true])]
    private bool $showOnDashboard = true;

    /**
     * Where the user put it among the accounts of its type (asset accounts are
     * dragged into order on their list). Null until the user first reorders:
     * those come after the ordered ones, oldest first. See AccountRepository::ORDER.
     */
    #[ORM\Column(nullable: true)]
    private ?int $position = null;

    /**
     * Set once at creation for asset accounts and never changed afterwards:
     * it drives investment-vs-cash logic that must not shift under a live account.
     */
    #[ORM\Column(length: 20, enumType: AssetAccountKind::class, nullable: true)]
    private ?AssetAccountKind $assetKind = null;

    #[ORM\ManyToOne(targetEntity: Currency::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Currency $currency = null;

    #[ORM\Column(type: 'decimal', precision: 19, scale: 4, nullable: true)]
    private ?string $openingBalance = '0.0000';

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $openingBalanceDate = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct(User $user, AccountType $type, string $name)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->type = $type;
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getType(): AccountType
    {
        return $this->type;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getIban(): ?string
    {
        return $this->iban;
    }

    public function setIban(?string $iban): static
    {
        $this->iban = $iban;
        return $this;
    }

    public function getBic(): ?string
    {
        return $this->bic;
    }

    public function setBic(?string $bic): static
    {
        $this->bic = $bic;
        return $this;
    }

    public function getAccountNumber(): ?string
    {
        return $this->accountNumber;
    }

    public function setAccountNumber(?string $accountNumber): static
    {
        $this->accountNumber = $accountNumber;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function isShownOnDashboard(): bool
    {
        return $this->showOnDashboard;
    }

    public function setShowOnDashboard(bool $showOnDashboard): static
    {
        $this->showOnDashboard = $showOnDashboard;
        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(?int $position): static
    {
        $this->position = $position;
        return $this;
    }

    public function getAssetKind(): ?AssetAccountKind
    {
        return $this->assetKind;
    }

    public function setAssetKind(?AssetAccountKind $assetKind): static
    {
        $this->assetKind = $assetKind;
        return $this;
    }

    public function getCurrency(): ?Currency
    {
        return $this->currency;
    }

    public function setCurrency(?Currency $currency): static
    {
        $this->currency = $currency;
        return $this;
    }

    public function getOpeningBalance(): ?string
    {
        return $this->openingBalance;
    }

    public function setOpeningBalance(?string $openingBalance): static
    {
        $this->openingBalance = $openingBalance;
        return $this;
    }

    public function getOpeningBalanceDate(): ?\DateTimeImmutable
    {
        return $this->openingBalanceDate;
    }

    public function setOpeningBalanceDate(?\DateTimeImmutable $openingBalanceDate): static
    {
        $this->openingBalanceDate = $openingBalanceDate;
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

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function markDeleted(): void
    {
        $this->deletedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
